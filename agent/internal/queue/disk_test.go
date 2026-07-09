package queue

import (
	"testing"
	"time"
)

func TestDiskQueueEnqueueDedup(t *testing.T) {
	dir := t.TempDir()
	q, err := Open(dir, 100, 10)
	if err != nil {
		t.Fatal(err)
	}
	ev := Event{EventType: "telemetry", DedupKey: "k1", Payload: map[string]interface{}{"a": 1}}
	if err := q.Enqueue(ev); err != nil {
		t.Fatal(err)
	}
	if err := q.Enqueue(ev); err != nil {
		t.Fatal(err)
	}
	st := q.Stats()
	if st.QueuedEvents != 1 {
		t.Fatalf("expected 1 event, got %d", st.QueuedEvents)
	}
}

func TestDiskQueueAck(t *testing.T) {
	dir := t.TempDir()
	q, _ := Open(dir, 100, 10)
	_ = q.Enqueue(Event{EventType: "heartbeat", DedupKey: "h1", EventAt: time.Now().UTC().Format(time.RFC3339)})
	_ = q.Ack([]string{"h1"})
	if q.Stats().QueuedEvents != 0 {
		t.Fatalf("expected empty queue after ack")
	}
}
