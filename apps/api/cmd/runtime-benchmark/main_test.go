package main

import (
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func validOptions() options {
	return options{mode: "seed", databaseURL: "postgres://synthetic:only@localhost/quickquiz_runtime_benchmark_test", questions: 100000, clients: 10, operations: 1000, warmup: 100, promptBytes: 256, answerBytes: 96, applyFixture: true}
}

func TestDatabaseAndMutationGuards(t *testing.T) {
	o := validOptions()
	if err := validateOptions(o); err != nil {
		t.Fatal(err)
	}
	for _, database := range []string{"quickquiz", "quickquiz_runtime_test", "quickquiz_runtime_benchmark_test_backup"} {
		bad := o
		bad.databaseURL = "postgres://reader:private-synthetic-value@localhost/" + database
		err := validateOptions(bad)
		if err == nil || strings.Contains(err.Error(), "private-synthetic-value") {
			t.Fatal("database guard did not reject safely")
		}
	}
	o.applyFixture = false
	if validateOptions(o) == nil {
		t.Fatal("fixture mutation accepted without explicit flag")
	}
	o = validOptions()
	o.mode = "measure"
	o.redisAddr = "localhost:6379"
	o.redisDB = 15
	if validateOptions(o) == nil {
		t.Fatal("Redis writes accepted without disposable acknowledgement")
	}
	o.disposableRedis = true
	o.redisDB = 0
	if validateOptions(o) == nil {
		t.Fatal("default Redis database accepted")
	}
}

func TestSyntheticDistributionPreservesHotScope(t *testing.T) {
	topics := map[int]int{1: 10000}
	for n := 1; n <= 90000; n++ {
		m := (n-1)%1999 + 2
		topics[m]++
	}
	if len(topics) != 2000 || topics[1] != 10000 {
		t.Fatal("base distribution does not satisfy skewed fixture")
	}
	baseHotTheme := 0
	for m, n := range topics {
		if m <= 10 {
			baseHotTheme += n
		}
	}
	for n := 1; n <= 100000; n++ {
		m := (n-1)%1990 + 11
		topics[m]++
	}
	doubledHotTheme := 0
	total := 0
	for m, n := range topics {
		total += n
		if m <= 10 {
			doubledHotTheme += n
		}
	}
	if total != 200000 || baseHotTheme != doubledHotTheme || topics[1] != 10000 {
		t.Fatal("extension changed the measured theme scope")
	}
}

func TestParallelAndLatencyAccounting(t *testing.T) {
	var calls atomic.Int64
	parallel(1000, 10, func(int) { calls.Add(1) })
	if calls.Load() != 1000 {
		t.Fatal("operation count mismatch")
	}
	v := make([]time.Duration, 1000)
	for i := range v {
		v[i] = time.Duration(i+1) * time.Millisecond
	}
	if durationMS(v, .95) != 950 || durationMS(v, .5) != 500 || durationMS(v, 1) != 1000 {
		t.Fatal("unexpected nearest-rank latency accounting")
	}
}
