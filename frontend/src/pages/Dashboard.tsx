import { useEffect, useState } from 'react'
import { dashboardService, DashboardData } from '../services/dashboardService'

function Dashboard() {
  const [data, setData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fetchDashboard = async () => {
      try {
        const dashboardData = await dashboardService.getDashboard()
        setData(dashboardData)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load dashboard')
      } finally {
        setLoading(false)
      }
    }

    fetchDashboard()
  }, [])

  if (loading) {
    return <div>Loading...</div>
  }

  if (error) {
    return <div>Error: {error}</div>
  }

  if (!data) {
    return <div>No data available</div>
  }

  return (
    <div>
      <h1>Dashboard</h1>

      {/* Platform Health Section */}
      <section>
        <h2>Platform Health</h2>
        <p>Status: {data.platform_health}</p>
      </section>

      {/* Modules Overview Section */}
      <section>
        <h2>Modules Overview</h2>
        <div>
          {data.modules.map((module) => (
            <div key={module.id} style={{ border: '1px solid #ccc', padding: '10px', margin: '10px 0' }}>
              <h3>{module.name}</h3>
              <p>{module.description}</p>
              <p>Status: {module.status}</p>
              <p>Subscription: {module.subscription_state}</p>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}

export default Dashboard
