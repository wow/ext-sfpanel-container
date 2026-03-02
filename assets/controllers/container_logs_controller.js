import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = {
        url: String,
    }

    static targets = ['output']

    connect() {
        this._timestamps = false
        this._eventSource = null
        this._active = false

        this._visCheck = setInterval(() => this.#checkVisibility(), 300)
        this.#checkVisibility()
    }

    disconnect() {
        this.#stop()
        clearInterval(this._visCheck)
    }

    toggleTimestamps(event) {
        this._timestamps = event.target.checked
        if (this._active) {
            this.#stop()
            this.#start()
        }
    }

    #checkVisibility() {
        const visible = this.element.offsetParent !== null
        if (visible && !this._active) this.#start()
        else if (!visible && this._active) this.#stop()
    }

    #start() {
        this._active = true
        const url = new URL(this.urlValue, window.location.origin)
        if (this._timestamps) {
            url.searchParams.set('timestamps', '1')
        }

        this._eventSource = new EventSource(url)
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

        if (data.logs !== undefined) {
            const output = this.outputTarget
            const wasAtBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 50
            output.textContent = data.logs || '(no logs)'
            if (wasAtBottom) {
                output.scrollTop = output.scrollHeight
            }
        }
    }
}
