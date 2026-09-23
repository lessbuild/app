document.querySelectorAll('[data-project-connection-form]').forEach((form) => {
  const source = form.elements.namedItem('source_resource_id')
  const target = form.elements.namedItem('target_resource_id')
  const hint = form.querySelector('[data-connection-hint]')
  const capabilities = [...form.querySelectorAll('[data-connection-capability]')]

  const updateCapabilities = () => {
    const sourceProduct = source?.selectedOptions[0]?.dataset.product
    const targetProduct = target?.selectedOptions[0]?.dataset.product
    let available = 0

    capabilities.forEach((row) => {
      const matches = sourceProduct
        && targetProduct
        && sourceProduct !== targetProduct
        && row.dataset.sourceProduct === sourceProduct
        && row.dataset.targetProduct === targetProduct
      const checkbox = row.querySelector('input[type="checkbox"]')

      row.hidden = !matches

      if (checkbox) {
        checkbox.disabled = !matches

        if (!matches) {
          checkbox.checked = false
        }
      }

      if (matches) {
        available += 1
      }
    })

    if (hint) {
      hint.hidden = available > 0
      hint.textContent = sourceProduct && targetProduct
        ? hint.dataset.hintUnavailable
        : hint.dataset.hintDefault
    }
  }

  source?.addEventListener('change', updateCapabilities)
  target?.addEventListener('change', updateCapabilities)
  updateCapabilities()
})

document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (! window.confirm(form.dataset.confirm)) {
      event.preventDefault()
    }
  })
})
