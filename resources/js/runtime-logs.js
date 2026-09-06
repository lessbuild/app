const initialiseLogConsole = (consoleElement) => {
    const output = consoleElement.querySelector('[data-log-output]')
    const search = consoleElement.querySelector('[data-log-search]')
    const level = consoleElement.querySelector('[data-log-level]')
    const live = consoleElement.querySelector('[data-log-live]')
    const status = consoleElement.querySelector('[data-log-status]')
    const refreshUrl = consoleElement.dataset.refreshUrl
    const reportUrl = consoleElement.dataset.reportUrl
    const token = document.querySelector('meta[name="csrf-token"]')?.content
    let source = output.textContent
    let timer = null

    const filter = () => {
        const query = search.value.trim().toLowerCase()
        const selectedLevel = level.value.toLowerCase()
        output.textContent = source.split('\n').filter((line) => {
            const normalized = line.toLowerCase()
            return (!query || normalized.includes(query))
                && (!selectedLevel || normalized.includes(selectedLevel))
        }).join('\n') || 'No matching log lines.'
    }

    const report = async () => {
        const response = await fetch(reportUrl, { headers: { Accept: 'application/json' } })
        if (!response.ok) return
        const payload = await response.json()
        source = payload.log || ''
        status.textContent = payload.error || `${payload.status} · ${payload.refreshed_at || 'not collected'}`
        filter()
    }

    const refresh = async () => {
        await fetch(refreshUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
        })
        window.setTimeout(report, 1500)
    }

    search.addEventListener('input', filter)
    level.addEventListener('change', filter)
    live.addEventListener('change', () => {
        window.clearInterval(timer)
        timer = live.checked ? window.setInterval(refresh, 10000) : null
        if (live.checked) refresh()
    })
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-runtime-log-console]').forEach(initialiseLogConsole)
})
