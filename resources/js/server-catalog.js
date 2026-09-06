const catalogFields = ['image', 'region', 'size']

const replaceOptions = (select, items) => {
    const selected = select.dataset.selected || select.value
    select.replaceChildren(...items.map(({ id, label }) => {
        const option = document.createElement('option')
        option.value = id
        option.textContent = label
        option.selected = id === selected

        return option
    }))
}

const initialiseCatalog = (container) => {
    const provider = container.querySelector('#provider_id')
    const status = container.querySelector('[data-server-catalog-status]')
    const selects = Object.fromEntries(catalogFields.map((field) => [field, container.querySelector(`#${field}`)]))
    let request = 0

    const load = async () => {
        const currentRequest = ++request
        const url = provider?.selectedOptions[0]?.dataset.catalogUrl
        if (!url) return

        status.textContent = 'Loading current regions, sizes, and Ubuntu images…'
        Object.values(selects).forEach((select) => { select.disabled = true })

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } })
            const payload = await response.json()
            if (!response.ok) throw new Error(payload.message || 'The provider catalog could not be loaded.')
            if (currentRequest !== request) return

            replaceOptions(selects.image, payload.images || [])
            replaceOptions(selects.region, payload.regions || [])
            replaceOptions(selects.size, payload.sizes || [])

            const missing = catalogFields.filter((field) => selects[field].options.length === 0)
            if (missing.length) {
                throw new Error(`No compatible ${missing.join(', ')} options were returned by this provider.`)
            }

            status.textContent = 'Provider options are up to date.'
        } catch (error) {
            if (currentRequest !== request) return
            status.textContent = error.message
        } finally {
            if (currentRequest === request) {
                Object.values(selects).forEach((select) => { select.disabled = false })
            }
        }
    }

    provider?.addEventListener('change', () => {
        catalogFields.forEach((field) => { selects[field].dataset.selected = '' })
        load()
    })
    load()
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-server-catalog]').forEach(initialiseCatalog)
})
