package store

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
)

type RunIndexMigrationOptions struct {
	Apply bool
	// Redis cannot detect processes running a pre-index binary. An operator must
	// stop them and explicitly confirm the deployment guard before completion.
	ConfirmLegacyWritersStopped bool
	BatchSize                   int
}

type RunIndexMigrationReport struct {
	Version                    string `json:"version"`
	Apply                      bool   `json:"apply"`
	LegacyWritersStopConfirmed bool   `json:"legacyWritersStopConfirmed"`
	ScannedKeys                int    `json:"scannedKeys"`
	VisitedRunRecords          int    `json:"visitedRunRecords"`
	IndexedRuns                int    `json:"indexedRuns"`
	ExpiredRuns                int    `json:"expiredRuns"`
	LegacyDependentVisits      int    `json:"legacyDependentVisits"`
	// SCAN can revisit keys while compatible writers mutate the keyspace. Visit
	// counts are not advertised as an exact count of distinct retained runs.
	ScanMayRepeatKeys bool `json:"scanMayRepeatKeys"`
	Completed         bool `json:"completed"`
	LegacyDependent   *int `json:"legacyDependent,omitempty"`
}

const migrationLease = 30 * time.Second

var beginIndexMigrationScript = redis.NewScript(`
if not redis.call('SET',KEYS[1],ARGV[1],'PX',ARGV[2],'NX') then return 0 end
redis.call('DEL',KEYS[2])
return 1
`)
var renewIndexMigrationScript = redis.NewScript(`
if redis.call('GET',KEYS[1])~=ARGV[1] then return 0 end
redis.call('PEXPIRE',KEYS[1],ARGV[2]); return 1
`)
var releaseIndexMigrationScript = redis.NewScript(`
if redis.call('GET',KEYS[1])~=ARGV[1] then return 0 end
return redis.call('DEL',KEYS[1])
`)
var finishIndexMigrationScript = redis.NewScript(`
if redis.call('GET',KEYS[1])~=ARGV[1] then return redis.error_reply('RUN_INDEX_MIGRATION_LEASE_LOST') end
local legacytype=redis.call('TYPE',KEYS[3]).ok
if legacytype~='none' and legacytype~='zset' then return redis.error_reply('RUN_INDEX_WRONG_TYPE') end
local scopetype=redis.call('TYPE',KEYS[4]).ok
if scopetype~='none' and scopetype~='zset' then return redis.error_reply('RUN_INDEX_WRONG_TYPE') end
redis.call('SET',KEYS[2],ARGV[2])
redis.call('DEL',KEYS[1])
return 1
`)

// Compare the record read by Go with its CURRENT value inside the script. A
// compatible writer racing backfill always wins with its own current metadata.
// The original run is never SET, PEXPIREd, or otherwise rewritten by migration.
var backfillRunScript = redis.NewScript(`
local function typed(key,wanted)
 local t=redis.call('TYPE',key).ok
 return t=='none' or t==wanted
end
if not typed(KEYS[1],'string') or not typed(KEYS[2],'string') or not typed(KEYS[3],'zset') or not typed(KEYS[4],'zset') or not typed(KEYS[6],'zset') then return redis.error_reply('RUN_INDEX_WRONG_TYPE') end
if redis.call('GET',KEYS[5])~=ARGV[5] then return redis.error_reply('RUN_INDEX_MIGRATION_LEASE_LOST') end
local current=redis.call('GET',KEYS[1])
if not current then return 2 end
if current~=ARGV[1] then return 0 end
local ttl=redis.call('PTTL',KEYS[1])
if ttl==-1 then return redis.error_reply('RUN_INDEX_RUN_WITHOUT_EXPIRY') end
if ttl<=0 then return 2 end
local meta=cjson.decode(ARGV[2])
local previous=redis.call('GET',KEYS[2])
if previous and cjson.decode(previous).sessionIndex~=meta.sessionIndex then return redis.error_reply('RUN_INDEX_OWNERSHIP_CHANGED') end
local now=redis.call('TIME'); now=tonumber(now[1])*1000+math.floor(tonumber(now[2])/1000)
local expiry=now+ttl
redis.call('SET',KEYS[2],ARGV[2],'PX',ttl)
redis.call('ZREMRANGEBYSCORE',KEYS[3],'-inf',now)
redis.call('ZADD',KEYS[3],expiry,ARGV[3])
local latest=redis.call('ZREVRANGE',KEYS[3],0,0,'WITHSCORES')
redis.call('PEXPIREAT',KEYS[3],math.floor(tonumber(latest[2])))
redis.call('ZREMRANGEBYSCORE',KEYS[6],'-inf',now)
redis.call('ZADD',KEYS[6],latest[2],KEYS[3])
local scopes=redis.call('ZREVRANGE',KEYS[6],0,0,'WITHSCORES')
redis.call('PEXPIREAT',KEYS[6],math.floor(tonumber(scopes[2])))
redis.call('ZREMRANGEBYSCORE',KEYS[4],'-inf',now)
if ARGV[4]=='1' then redis.call('ZADD',KEYS[4],expiry,ARGV[3]) else redis.call('ZREM',KEYS[4],ARGV[3]) end
local legacy=redis.call('ZREVRANGE',KEYS[4],0,0,'WITHSCORES')
if #legacy>0 then redis.call('PEXPIREAT',KEYS[4],math.floor(tonumber(legacy[2]))) end
return 1
`)

func (s *RedisRunStore) MigrateRunIndex(ctx context.Context, options RunIndexMigrationOptions) (report RunIndexMigrationReport, err error) {
	report = RunIndexMigrationReport{Version: runIndexVersion, Apply: options.Apply, LegacyWritersStopConfirmed: options.ConfirmLegacyWritersStopped, ScanMayRepeatKeys: true}
	if options.BatchSize == 0 {
		options.BatchSize = 100
	}
	if options.BatchSize < 1 || options.BatchSize > 1000 {
		return report, errors.New("run index migration batch size must be between 1 and 1000")
	}
	if options.Apply && !options.ConfirmLegacyWritersStopped {
		return report, errors.New("run index apply requires confirmation that all pre-index API writers are stopped")
	}
	var token string
	if options.Apply {
		bytes := make([]byte, 16)
		if _, err = rand.Read(bytes); err != nil {
			return report, err
		}
		token = hex.EncodeToString(bytes)
		var acquired int
		acquired, err = beginIndexMigrationScript.Run(ctx, s.client, []string{s.migrationLockKey(), s.indexMarkerKey()}, token, migrationLease.Milliseconds()).Int()
		if err != nil {
			return report, indexError(err)
		}
		if acquired != 1 {
			return report, fmt.Errorf("run index migration already in progress")
		}
		defer func() {
			cleanup, cancel := context.WithTimeout(context.Background(), 2*time.Second)
			defer cancel()
			_ = releaseIndexMigrationScript.Run(cleanup, s.client, []string{s.migrationLockKey()}, token).Err()
		}()
	}
	var cursor uint64
	for {
		if err = ctx.Err(); err != nil {
			return report, err
		}
		if options.Apply {
			var renewed int
			renewed, err = renewIndexMigrationScript.Run(ctx, s.client, []string{s.migrationLockKey()}, token, migrationLease.Milliseconds()).Int()
			if err != nil {
				return report, indexError(err)
			}
			if renewed != 1 {
				return report, errors.New("run index migration lease was lost")
			}
		}
		var keys []string
		var next uint64
		keys, next, err = s.client.Scan(ctx, cursor, s.keyPrefix+"*", int64(options.BatchSize)).Result()
		if err != nil {
			return report, indexError(err)
		}
		report.ScannedKeys += len(keys)
		// COUNT is a Redis hint, so process even an oversized reply in bounded
		// chunks. Only one run payload and its compact metadata are decoded at once.
		for start := 0; start < len(keys); start += options.BatchSize {
			end := start + options.BatchSize
			if end > len(keys) {
				end = len(keys)
			}
			for _, key := range keys[start:end] {
				indexed, expired, legacy, visitErr := s.migrateRun(ctx, key, token, options.Apply)
				if visitErr != nil {
					return report, visitErr
				}
				if expired {
					report.ExpiredRuns++
					continue
				}
				report.VisitedRunRecords++
				if indexed {
					report.IndexedRuns++
				}
				if legacy {
					report.LegacyDependentVisits++
				}
			}
		}
		cursor = next
		if cursor == 0 {
			break
		}
	}
	if options.Apply {
		_, err = finishIndexMigrationScript.Run(ctx, s.client, []string{s.migrationLockKey(), s.indexMarkerKey(), s.legacyIndexKey(), s.scopeRegistryKey()}, token, runIndexVersion).Result()
		if err != nil {
			return report, indexError(err)
		}
		report.Completed = true
		var count int
		count, err = s.CountLegacyDependent(ctx)
		if err != nil {
			return report, err
		}
		report.LegacyDependent = &count
	} else {
		// Validation of the existing marker is a read, not a completion claim.
		report.Completed = s.IndexReady(ctx) == nil
	}
	return report, nil
}

func (s *RedisRunStore) migrateRun(ctx context.Context, key, token string, apply bool) (indexed, expired, legacy bool, err error) {
	for attempt := 0; attempt < redisTransactionRetries; attempt++ {
		payload, readErr := s.client.Get(ctx, key).Bytes()
		if errors.Is(readErr, redis.Nil) {
			return false, true, false, nil
		}
		if readErr != nil {
			return false, false, false, indexError(readErr)
		}
		run, decodeErr := decodeRedisRun(payload)
		if decodeErr != nil {
			return false, false, false, indexError(errors.New("invalid run record during migration"))
		}
		metadata, metaErr := s.metadata(run)
		if metaErr != nil || s.key(run.ID) != key {
			return false, false, false, indexError(errors.New("invalid run identity during migration"))
		}
		legacy = run.Version < 2 && !run.Finished
		if !apply {
			ttl, ttlErr := s.client.PTTL(ctx, key).Result()
			if ttlErr != nil {
				return false, false, false, indexError(ttlErr)
			}
			if ttl == -1 {
				return false, false, false, errors.New("run index migration found a run without expiry")
			}
			if ttl <= 0 {
				return false, true, false, nil
			}
			return false, false, legacy, nil
		}
		isLegacy := "0"
		if legacy {
			isLegacy = "1"
		}
		status, writeErr := backfillRunScript.Run(ctx, s.client, []string{key, s.runMetadataKey(run.ID), s.sessionIndexKey(run.SessionID, run.Theme), s.legacyIndexKey(), s.migrationLockKey(), s.scopeRegistryKey()}, payload, metadata, run.ID, isLegacy, token).Int()
		if writeErr != nil {
			return false, false, false, indexError(writeErr)
		}
		if status == 1 {
			return true, false, legacy, nil
		}
		if status == 2 {
			return false, true, false, nil
		}
	}
	return false, false, false, indexError(errors.New("run index migration concurrent update retry limit reached"))
}
