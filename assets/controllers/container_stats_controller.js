import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = {
        url: String,
    }

    static targets = ['cpu', 'cpuBar', 'memory', 'memoryBar', 'netRx', 'netTx', 'pids']

    connect() {
        this._eventSource = null
        this._active = false

        this._visCheck = setInterval(() => this.#checkVisibility(), 300)
        this.#checkVisibility()
    }

    disconnect() {
        this.#stop()
        clearInterval(this._visCheck)
    }

    #checkVisibility() {
        const visible = this.element.offsetParent !== null
        if (visible && !this._active) this.#start()
        else if (!visible && this._active) this.#stop()
    }

    #start() {
        this._active = true
        this._eventSource = new EventSource(this.urlValue)
        this._eventSource.onmessage = (event) => this.#update(event)
    }

    #stop() {
        this._active = false
        if (this._eventSource) {
            this._eventSource.close()
            this._eventSource = null
        }
    }

    #update(event) {
        let data
        try {
            data = JSON.parse(event.data)
        } catch {
            return
        }

        if (data.error) return

        if (this.hasCpuTarget) {
            this.cpuTarget.textContent = data.cpuPercent.toFixed(1) + '%'
        }
        if (this.hasCpuBarTarget) {
            this.cpuBarTarget.value = Math.min(data.cpuPercent, 100)
        }
        if (this.hasMemoryTarget) {
            this.memoryTarget.textContent = this.formatBytes(data.memoryUsage) + ' / ' + this.formatBytes(data.memoryLimit)
        }
        if (this.hasMemoryBarTarget) {
            this.memoryBarTarget.value = Math.min(data.memoryPercent, 100)
        }
        if (this.hasNetRxTarget) {
            this.netRxTarget.textContent = this.formatBytes(data.networkRx)
        }
        if (this.hasNetTxTarget) {
            this.netTxTarget.textContent = this.formatBytes(data.networkTx)
        }
        if (this.hasPidsTarget) {
            this.pidsTarget.textContent = data.pids
        }
    }

    formatBytes(bytes) {
        if (bytes === 0) return '0 B'
        const units = ['B', 'KB', 'MB', 'GB', 'TB']
        const i = Math.floor(Math.log(bytes) / Math.log(1024))
        return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i]
    }
}
