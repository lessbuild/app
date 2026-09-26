const activateProductExplorer = (explorer) => {
  const tabList = explorer.querySelector('[data-product-tabs]');
  const tabs = [...explorer.querySelectorAll('[data-product-tab]')];
  const panels = [...explorer.querySelectorAll('[data-product-panel]')];

  if (!tabList || tabs.length === 0 || panels.length === 0) return;

  tabList.setAttribute('role', 'tablist');
  tabList.setAttribute('aria-orientation', 'horizontal');

  tabs.forEach((tab) => {
    const slug = tab.dataset.productTab;
    const panel = panels.find((item) => item.dataset.productPanel === slug);

    if (!panel) return;

    tab.id = `product-explorer-tab-${slug}`;
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-controls', panel.id);
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('aria-labelledby', tab.id);
    panel.setAttribute('tabindex', '0');
  });

  const validTabs = tabs.filter((tab) => panels.some((panel) => panel.dataset.productPanel === tab.dataset.productTab));

  if (validTabs.length === 0) return;

  const activate = (slug, moveFocus = false) => {
    validTabs.forEach((tab) => {
      const selected = tab.dataset.productTab === slug;

      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;

      if (selected && moveFocus) tab.focus();

      const relatedPanel = panels.find((item) => item.dataset.productPanel === tab.dataset.productTab);

      if (relatedPanel) {
        relatedPanel.hidden = !selected;
        relatedPanel.classList.toggle('hidden', !selected);
        relatedPanel.setAttribute('aria-hidden', String(!selected));
      }
    });
  };

  validTabs.forEach((tab, index) => {
    tab.addEventListener('click', (event) => {
      event.preventDefault();
      activate(tab.dataset.productTab);
    });

    tab.addEventListener('keydown', (event) => {
      if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;

      event.preventDefault();

      const nextIndex = event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? validTabs.length - 1
          : (index + (event.key === 'ArrowRight' ? 1 : -1) + validTabs.length) % validTabs.length;

      activate(validTabs[nextIndex].dataset.productTab, true);
    });
  });

  const hashPanel = panels.find((panel) => panel.id === window.location.hash.slice(1));
  const initialSlug = hashPanel?.dataset.productPanel
    || validTabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.productTab
    || validTabs[0].dataset.productTab;

  activate(initialSlug);
};

document.querySelectorAll('[data-product-explorer]').forEach(activateProductExplorer);

const activateAnalyticsPreview = (preview) => {
  const select = preview.querySelector('[data-product-analytics-range]');

  if (!select) return;

  const update = () => {
    const option = select.selectedOptions[0];

    if (!option) return;

    preview.querySelector('[data-product-visitors]')?.replaceChildren(option.dataset.visitors || '');
    preview.querySelector('[data-product-change]')?.replaceChildren(option.dataset.change || '');
    preview.querySelector('[data-product-analytics-period]')?.replaceChildren(option.dataset.label || option.textContent.trim());
    preview.querySelector('[data-product-analytics-summary]')?.replaceChildren(option.dataset.summary || '');
    preview.querySelector('[data-product-analytics-status]')?.replaceChildren(option.dataset.announcement || '');

    const chart = preview.querySelector('[data-product-traffic-chart]');
    const path = option.dataset.chartPath;

    if (chart && path) {
      chart.querySelector('[data-product-traffic-line]')?.setAttribute('d', path);
      chart.querySelector('[data-product-traffic-fill]')?.setAttribute('d', `${path}V112H0Z`);
      chart.setAttribute('aria-label', option.dataset.chartLabel || '');
    }
  };

  select.addEventListener('change', update);
  update();
  select.disabled = false;
};

document.querySelectorAll('[data-product-analytics]').forEach(activateAnalyticsPreview);
