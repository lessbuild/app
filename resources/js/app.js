import './bootstrap'
import './signal-topbar'
import './server-catalog'
import './project-connections'

if (document.documentElement.dataset.product === 'monitor') {
  import('../../app/Modules/Monitor/Resources/Assets/js/app.js')
}
