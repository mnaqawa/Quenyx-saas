interface Tab {
  id: string
  label: string
}

interface TabsProps {
  tabs: Tab[]
  activeTab: string
  onTabChange: (tabId: string) => void
}

export function Tabs({ tabs, activeTab, onTabChange }: TabsProps) {
  return (
    <div className="mb-6 flex gap-1 border-b border-white/10">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          onClick={() => onTabChange(tab.id)}
          className={`px-4 py-2 text-sm font-medium transition ${
            activeTab === tab.id
              ? 'border-b-2 border-sky-500 text-white'
              : 'text-white/60 hover:text-white'
          }`}
        >
          {tab.label}
        </button>
      ))}
    </div>
  )
}
