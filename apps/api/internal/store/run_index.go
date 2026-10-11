package store

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"sort"

	"github.com/redis/go-redis/v9"

	"quickquiz/api/internal/domain"
)

const runIndexVersion = "1"

// These keys deliberately live OUTSIDE the legacy run prefix. Old SCAN-based
// administration and the explicit migration must never decode an index as a run.
func (s *RedisRunStore) indexPrefix() string {
	sum := sha256.Sum256([]byte(s.keyPrefix))
	return "__quickquiz_run_index_v1:" + hex.EncodeToString(sum[:]) + ":"
}

func (s *RedisRunStore) indexMarkerKey() string          { return s.indexPrefix() + "complete" }
func (s *RedisRunStore) legacyIndexKey() string          { return s.indexPrefix() + "legacy" }
func (s *RedisRunStore) migrationLockKey() string        { return s.indexPrefix() + "migration" }
func (s *RedisRunStore) scopeRegistryKey() string        { return s.indexPrefix() + "scopes" }
func (s *RedisRunStore) runMetadataKey(id string) string { return s.indexPrefix() + "run:" + id }
func (s *RedisRunStore) sessionIndexKey(session, theme string) string {
	identity, _ := json.Marshal([]string{session, theme})
	sum := sha256.Sum256(identity)
	return s.indexPrefix() + "session:" + hex.EncodeToString(sum[:])
}

type indexedRun struct {
	ID           string            `json:"id"`
	SessionIndex string            `json:"sessionIndex"`
	Topic        string            `json:"topic"`
	Difficulty   domain.Difficulty `json:"difficulty"`
	Used         map[string]bool   `json:"used"`
	Version      int               `json:"version"`
	Finished     bool              `json:"finished"`
}

func (s *RedisRunStore) metadata(run *domain.Run) ([]byte, error) {
	if run == nil || run.ID == "" || run.SessionID == "" || run.Theme == "" {
		return nil, errors.New("run identity is incomplete")
	}
	return json.Marshal(indexedRun{ID: run.ID, SessionIndex: s.sessionIndexKey(run.SessionID, run.Theme), Topic: run.Topic, Difficulty: run.Difficulty, Used: run.UsedQuestionIDs, Version: run.Version, Finished: run.Finished})
}

func indexError(err error) error {
	if err == nil {
		return nil
	}
	// Preserve the public availability sentinel without embedding record/credential data.
	return fmt.Errorf("%w: Redis run index operation failed: %w", domain.ErrStorageUnavailable, err)
}

// A wholly empty Redis DB is the only safe automatic bootstrap. A nonempty DB
// without a marker requires explicit operator backfill, even if other namespaces
// account for its keys; guessing would silently discard legacy consumption.
var bootstrapRunIndexScript = redis.NewScript(`
if redis.call('GET',KEYS[1]) == ARGV[1] then return 1 end
if redis.call('DBSIZE') == 0 then redis.call('SET',KEYS[1],ARGV[1]); return 1 end
return 0
`)

func (s *RedisRunStore) bootstrapIndex(ctx context.Context) error {
	_, err := bootstrapRunIndexScript.Run(ctx, s.client, []string{s.indexMarkerKey()}, runIndexVersion).Result()
	return indexError(err)
}

func (s *RedisRunStore) IndexReady(ctx context.Context) error {
	version, err := s.client.Get(ctx, s.indexMarkerKey()).Result()
	if errors.Is(err, redis.Nil) {
		return fmt.Errorf("%w: run index migration is incomplete", domain.ErrStorageUnavailable)
	}
	if err != nil {
		return indexError(err)
	}
	if version != runIndexVersion {
		return fmt.Errorf("%w: unsupported run index version", domain.ErrStorageUnavailable)
	}
	return nil
}

func (s *RedisRunStore) Ready(ctx context.Context) error {
	if err := s.client.Ping(ctx).Err(); err != nil {
		return indexError(err)
	}
	return s.IndexReady(ctx)
}

// All key types and immutable ownership are checked BEFORE the first mutation:
// Redis Lua errors do not roll back writes performed earlier in the script.
const indexedWriteLua = `
local function typed(key,wanted)
 local t=redis.call('TYPE',key).ok
 if t~='none' and t~=wanted then return false end
 return true
end
if not typed(KEYS[1],'string') or not typed(KEYS[2],'string') or not typed(KEYS[3],'zset') or not typed(KEYS[4],'zset') or not typed(KEYS[5],'string') or not typed(KEYS[6],'zset') then return redis.error_reply('RUN_INDEX_WRONG_TYPE') end
if ARGV[6]=='1' and redis.call('GET',KEYS[5])~=ARGV[7] then return redis.error_reply('RUN_INDEX_INCOMPLETE') end
local meta=cjson.decode(ARGV[2])
local previous=redis.call('GET',KEYS[2])
if previous and cjson.decode(previous).sessionIndex~=meta.sessionIndex then return redis.error_reply('RUN_INDEX_OWNERSHIP_CHANGED') end
local ttl=tonumber(ARGV[3])
if not ttl or ttl<=0 then return redis.error_reply('RUN_INDEX_INVALID_TTL') end
local now=redis.call('TIME'); now=tonumber(now[1])*1000+math.floor(tonumber(now[2])/1000)
local expiry=now+ttl
redis.call('SET',KEYS[1],ARGV[1],'PX',ttl)
redis.call('SET',KEYS[2],ARGV[2],'PX',ttl)
redis.call('ZREMRANGEBYSCORE',KEYS[3],'-inf',now)
redis.call('ZADD',KEYS[3],expiry,ARGV[4])
local latest=redis.call('ZREVRANGE',KEYS[3],0,0,'WITHSCORES')
redis.call('PEXPIREAT',KEYS[3],math.floor(tonumber(latest[2])))
redis.call('ZREMRANGEBYSCORE',KEYS[6],'-inf',now)
redis.call('ZADD',KEYS[6],latest[2],KEYS[3])
local scopes=redis.call('ZREVRANGE',KEYS[6],0,0,'WITHSCORES')
redis.call('PEXPIREAT',KEYS[6],math.floor(tonumber(scopes[2])))
redis.call('ZREMRANGEBYSCORE',KEYS[4],'-inf',now)
if ARGV[5]=='1' then redis.call('ZADD',KEYS[4],expiry,ARGV[4]) else redis.call('ZREM',KEYS[4],ARGV[4]) end
local legacy=redis.call('ZREVRANGE',KEYS[4],0,0,'WITHSCORES')
if #legacy>0 then redis.call('PEXPIREAT',KEYS[4],math.floor(tonumber(legacy[2]))) end
return 1
`

func (s *RedisRunStore) writeIndexKeys(run *domain.Run) []string {
	return []string{s.key(run.ID), s.runMetadataKey(run.ID), s.sessionIndexKey(run.SessionID, run.Theme), s.legacyIndexKey(), s.indexMarkerKey(), s.scopeRegistryKey()}
}

func (s *RedisRunStore) writeIndexArgs(run *domain.Run, payload, metadata []byte, requireReady bool) []any {
	legacy, gate := "0", "0"
	if run.Version < 2 && !run.Finished {
		legacy = "1"
	}
	if requireReady {
		gate = "1"
	}
	return []any{payload, metadata, s.ttl.Milliseconds(), run.ID, legacy, gate, runIndexVersion}
}

// Session reads transfer compact consumption metadata, never question snapshots
// or another session's runs. The server clock also controls expiry pruning.
var readSessionIndexScript = redis.NewScript(`
if redis.call('GET',KEYS[1])~=ARGV[1] then return redis.error_reply('RUN_INDEX_INCOMPLETE') end
local now=redis.call('TIME'); now=tonumber(now[1])*1000+math.floor(tonumber(now[2])/1000)
local registered=redis.call('ZSCORE',KEYS[3],KEYS[2])
local present=redis.call('EXISTS',KEYS[2])==1
if (registered and tonumber(registered)>now and not present) or (present and (not registered or tonumber(registered)<=now)) then return redis.error_reply('RUN_INDEX_SCOPE_MISSING') end
redis.call('ZREMRANGEBYSCORE',KEYS[2],'-inf',now)
local ids=redis.call('ZRANGEBYSCORE',KEYS[2],now+1,'+inf')
local result={}
for _,id in ipairs(ids) do
 local raw=redis.call('GET',ARGV[2]..id)
 if not raw then
  if redis.call('EXISTS',ARGV[3]..id)==1 then return redis.error_reply('RUN_INDEX_METADATA_MISSING') end
  redis.call('ZREM',KEYS[2],id)
 else
  local meta=cjson.decode(raw)
  if meta.sessionIndex~=KEYS[2] or meta.id~=id then return redis.error_reply('RUN_INDEX_OWNERSHIP_INVALID') end
  if redis.call('EXISTS',ARGV[3]..id)==1 then table.insert(result,raw) else redis.call('ZREM',KEYS[2],id) end
 end
end
return result
`)

func (s *RedisRunStore) sessionMetadata(ctx context.Context, session, theme string) ([]indexedRun, error) {
	values, err := readSessionIndexScript.Run(ctx, s.client, []string{s.indexMarkerKey(), s.sessionIndexKey(session, theme), s.scopeRegistryKey()}, runIndexVersion, s.indexPrefix()+"run:", s.keyPrefix).StringSlice()
	if err != nil {
		return nil, indexError(err)
	}
	result := make([]indexedRun, 0, len(values))
	for _, value := range values {
		var metadata indexedRun
		if err := json.Unmarshal([]byte(value), &metadata); err != nil {
			return nil, indexError(errors.New("invalid run index metadata"))
		}
		result = append(result, metadata)
	}
	return result, nil
}

func consumptionFromRuns(runs []indexedRun) (domain.QuestionConsumption, error) {
	sets := map[string]map[domain.Difficulty]map[string]bool{}
	for _, run := range runs {
		for id, used := range run.Used {
			if !used {
				continue
			}
			if run.Topic == "" || !run.Difficulty.Valid() || id == "" {
				return nil, indexError(errors.New("invalid consumption identity"))
			}
			if sets[run.Topic] == nil {
				sets[run.Topic] = map[domain.Difficulty]map[string]bool{}
			}
			if sets[run.Topic][run.Difficulty] == nil {
				sets[run.Topic][run.Difficulty] = map[string]bool{}
			}
			sets[run.Topic][run.Difficulty][id] = true
		}
	}
	result := domain.QuestionConsumption{}
	for topic, levels := range sets {
		result[topic] = map[domain.Difficulty][]string{}
		for level, ids := range levels {
			for id := range ids {
				result[topic][level] = append(result[topic][level], id)
			}
			sort.Strings(result[topic][level])
		}
	}
	return result, nil
}

func (s *RedisRunStore) ConsumedBySession(ctx context.Context, session, theme string) (domain.QuestionConsumption, error) {
	runs, err := s.sessionMetadata(ctx, session, theme)
	if err != nil {
		return nil, err
	}
	return consumptionFromRuns(runs)
}

func (s *RedisRunStore) HasSession(ctx context.Context, session, theme string) (bool, error) {
	runs, err := s.sessionMetadata(ctx, session, theme)
	return len(runs) > 0, err
}

var countLegacyScript = redis.NewScript(`
if redis.call('GET',KEYS[1])~=ARGV[1] then return redis.error_reply('RUN_INDEX_INCOMPLETE') end
local now=redis.call('TIME'); now=tonumber(now[1])*1000+math.floor(tonumber(now[2])/1000)
redis.call('ZREMRANGEBYSCORE',KEYS[2],'-inf',now)
local ids=redis.call('ZRANGE',KEYS[2],0,-1)
for _,id in ipairs(ids) do
 local raw=redis.call('GET',ARGV[2]..id)
 if not raw then
  if redis.call('EXISTS',ARGV[3]..id)==1 then return redis.error_reply('RUN_INDEX_METADATA_MISSING') end
  redis.call('ZREM',KEYS[2],id)
 else
  local meta=cjson.decode(raw)
  if meta.version>=2 or meta.finished or redis.call('EXISTS',ARGV[3]..id)==0 then redis.call('ZREM',KEYS[2],id) end
 end
end
return redis.call('ZCARD',KEYS[2])
`)

func (s *RedisRunStore) CountLegacyDependent(ctx context.Context) (int, error) {
	count, err := countLegacyScript.Run(ctx, s.client, []string{s.indexMarkerKey(), s.legacyIndexKey()}, runIndexVersion, s.indexPrefix()+"run:", s.keyPrefix).Int()
	return count, indexError(err)
}

var deleteSessionIndexScript = redis.NewScript(`
if redis.call('GET',KEYS[1])~=ARGV[1] then return redis.error_reply('RUN_INDEX_INCOMPLETE') end
local legacytype=redis.call('TYPE',KEYS[3]).ok
if legacytype~='none' and legacytype~='zset' then return redis.error_reply('RUN_INDEX_WRONG_TYPE') end
local scopetype=redis.call('TYPE',KEYS[4]).ok
if scopetype~='none' and scopetype~='zset' then return redis.error_reply('RUN_INDEX_WRONG_TYPE') end
local now=redis.call('TIME'); now=tonumber(now[1])*1000+math.floor(tonumber(now[2])/1000)
local registered=redis.call('ZSCORE',KEYS[4],KEYS[2])
local present=redis.call('EXISTS',KEYS[2])==1
if (registered and tonumber(registered)>now and not present) or (present and (not registered or tonumber(registered)<=now)) then return redis.error_reply('RUN_INDEX_SCOPE_MISSING') end
local ids=redis.call('ZRANGE',KEYS[2],0,-1)
for _,id in ipairs(ids) do
 local raw=redis.call('GET',ARGV[2]..id)
 if not raw and redis.call('EXISTS',ARGV[3]..id)==1 then return redis.error_reply('RUN_INDEX_METADATA_MISSING') end
 if raw then local meta=cjson.decode(raw); if meta.sessionIndex~=KEYS[2] or meta.id~=id then return redis.error_reply('RUN_INDEX_OWNERSHIP_INVALID') end end
end
for _,id in ipairs(ids) do
 redis.call('DEL',ARGV[3]..id,ARGV[2]..id)
 redis.call('ZREM',KEYS[3],id)
end
redis.call('DEL',KEYS[2])
redis.call('ZREM',KEYS[4],KEYS[2])
return #ids
`)

func (s *RedisRunStore) deleteIndexedSession(ctx context.Context, session, theme string) error {
	_, err := deleteSessionIndexScript.Run(ctx, s.client, []string{s.indexMarkerKey(), s.sessionIndexKey(session, theme), s.legacyIndexKey(), s.scopeRegistryKey()}, runIndexVersion, s.indexPrefix()+"run:", s.keyPrefix).Result()
	return indexError(err)
}

func (s *MemoryRunStore) Ready(context.Context) error      { return nil }
func (s *MemoryRunStore) IndexReady(context.Context) error { return nil }

func (s *MemoryRunStore) ConsumedBySession(_ context.Context, session, theme string) (domain.QuestionConsumption, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cleanupLocked()
	runs := []indexedRun{}
	for _, run := range s.runs {
		if run.SessionID == session && run.Theme == theme {
			runs = append(runs, indexedRun{Topic: run.Topic, Difficulty: run.Difficulty, Used: run.UsedQuestionIDs})
		}
	}
	return consumptionFromRuns(runs)
}
func (s *MemoryRunStore) HasSession(_ context.Context, session, theme string) (bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cleanupLocked()
	for _, run := range s.runs {
		if run.SessionID == session && run.Theme == theme {
			return true, nil
		}
	}
	return false, nil
}
func (s *MemoryRunStore) CountLegacyDependent(context.Context) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cleanupLocked()
	count := 0
	for _, run := range s.runs {
		if run.Version < 2 && !run.Finished {
			count++
		}
	}
	return count, nil
}
