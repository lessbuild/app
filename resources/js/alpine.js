import Alpine from 'alpinejs'
import './server-catalog'
import './runtime-logs'
import { initSignalPublicDrawers } from './signal-drawer'

window.Alpine = Alpine

initSignalPublicDrawers()
Alpine.start()
