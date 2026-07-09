package queue

import (
	"compress/gzip"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"sync"
	"time"
)

// Event is a queued offline payload awaiting replay.
type Event struct {
	EventType string                 `json:"event_type"`
	DedupKey  string                 `json:"dedup_key"`
	EventAt   string                 `json:"event_at"`
	Payload   map[string]interface{} `json:"payload"`
}

// Stats mirrors platform queue_stats contract.
type Stats struct {
	QueuedEvents   int     `json:"queued_events"`
	OldestEvent    string  `json:"oldest_event,omitempty"`
	NewestEvent    string  `json:"newest_event,omitempty"`
	DroppedEvents  int     `json:"dropped_events"`
	ReplayProgress int     `json:"replay_progress"`
	DiskUsageMB    float64 `json:"disk_usage_mb"`
}

// DiskQueue persists events locally with compression and retention limits.
type DiskQueue struct {
	dir         string
	maxEvents   int
	maxDiskMB   int
	mu          sync.Mutex
	dropped     int
	replayDone  int
}

// Open creates or opens a disk-backed offline queue.
func Open(dir string, maxEvents, maxDiskMB int) (*DiskQueue, error) {
	if err := os.MkdirAll(dir, 0700); err != nil {
		return nil, err
	}
	q := &DiskQueue{dir: dir, maxEvents: maxEvents, maxDiskMB: maxDiskMB}
	if maxEvents <= 0 {
		q.maxEvents = 10000
	}
	if maxDiskMB <= 0 {
		q.maxDiskMB = 256
	}
	return q, nil
}

// Enqueue stores an event; drops oldest when over capacity.
func (q *DiskQueue) Enqueue(ev Event) error {
	q.mu.Lock()
	defer q.mu.Unlock()

	if ev.EventAt == "" {
		ev.EventAt = time.Now().UTC().Format(time.RFC3339)
	}
	if ev.DedupKey == "" {
		ev.DedupKey = fmt.Sprintf("%s-%d", ev.EventType, time.Now().UnixNano())
	}

	events, err := q.loadLocked()
	if err != nil {
		return err
	}

	for _, e := range events {
		if e.DedupKey == ev.DedupKey {
			return nil
		}
	}

	events = append(events, ev)
	for len(events) > q.maxEvents {
		events = events[1:]
		q.dropped++
	}

	return q.saveLocked(events)
}

// Drain returns events in chronological order for replay.
func (q *DiskQueue) Drain(limit int) ([]Event, error) {
	q.mu.Lock()
	defer q.mu.Unlock()

	events, err := q.loadLocked()
	if err != nil {
		return nil, err
	}
	sort.Slice(events, func(i, j int) bool {
		return events[i].EventAt < events[j].EventAt
	})
	if limit > 0 && len(events) > limit {
		events = events[:limit]
	}
	return events, nil
}

// Ack removes successfully replayed events by dedup key.
func (q *DiskQueue) Ack(dedupKeys []string) error {
	q.mu.Lock()
	defer q.mu.Unlock()

	events, err := q.loadLocked()
	if err != nil {
		return err
	}
	remove := map[string]bool{}
	for _, k := range dedupKeys {
		remove[k] = true
	}
	kept := make([]Event, 0, len(events))
	for _, e := range events {
		if remove[e.DedupKey] {
			q.replayDone++
			continue
		}
		kept = append(kept, e)
	}
	return q.saveLocked(kept)
}

// Stats returns current queue statistics.
func (q *DiskQueue) Stats() Stats {
	q.mu.Lock()
	defer q.mu.Unlock()

	events, _ := q.loadLocked()
	st := Stats{
		QueuedEvents:   len(events),
		DroppedEvents:  q.dropped,
		ReplayProgress: q.replayDone,
	}
	if len(events) > 0 {
		st.OldestEvent = events[0].EventAt
		st.NewestEvent = events[len(events)-1].EventAt
	}
	st.DiskUsageMB = q.diskUsageMBLocked()
	return st
}

func (q *DiskQueue) path() string {
	return filepath.Join(q.dir, "offline_queue.json.gz")
}

func (q *DiskQueue) loadLocked() ([]Event, error) {
	path := q.path()
	f, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return []Event{}, nil
		}
		return nil, err
	}
	defer f.Close()
	gr, err := gzip.NewReader(f)
	if err != nil {
		return nil, err
	}
	defer gr.Close()
	data, err := io.ReadAll(gr)
	if err != nil {
		return nil, err
	}
	var events []Event
	if len(data) == 0 {
		return []Event{}, nil
	}
	if err := json.Unmarshal(data, &events); err != nil {
		return nil, err
	}
	return events, nil
}

func (q *DiskQueue) saveLocked(events []Event) error {
	data, err := json.Marshal(events)
	if err != nil {
		return err
	}
	path := q.path()
	tmp := path + ".tmp"
	f, err := os.Create(tmp)
	if err != nil {
		return err
	}
	gw := gzip.NewWriter(f)
	if _, err := gw.Write(data); err != nil {
		f.Close()
		os.Remove(tmp)
		return err
	}
	if err := gw.Close(); err != nil {
		f.Close()
		os.Remove(tmp)
		return err
	}
	if err := f.Close(); err != nil {
		os.Remove(tmp)
		return err
	}
	return os.Rename(tmp, path)
}

func (q *DiskQueue) diskUsageMBLocked() float64 {
	info, err := os.Stat(q.path())
	if err != nil {
		return 0
	}
	return float64(info.Size()) / (1024 * 1024)
}

// ToMap converts Stats for heartbeat payload.
func (s Stats) ToMap() map[string]interface{} {
	return map[string]interface{}{
		"queued_events":   s.QueuedEvents,
		"oldest_event":    s.OldestEvent,
		"newest_event":    s.NewestEvent,
		"dropped_events":  s.DroppedEvents,
		"replay_progress": s.ReplayProgress,
		"disk_usage_mb":   s.DiskUsageMB,
	}
}
