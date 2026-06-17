export function ObservePageSkeleton() {
  return (
    <div className="space-y-6 p-6">
      <div className="h-10 w-72 max-w-full animate-pulse rounded-lg bg-white/5" />
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="h-28 animate-pulse rounded-2xl border border-white/10 bg-white/5" />
        ))}
      </div>
      <div className="h-72 animate-pulse rounded-2xl border border-white/10 bg-white/5" />
    </div>
  )
}
