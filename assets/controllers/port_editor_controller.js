import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = { ports: Object }
    static targets = ['portsData', 'serviceBlock']

    connect() {
        this.serviceBlockTargets.forEach(block => {
            const serviceName = block.dataset.service
            const ports = this.portsValue[serviceName] || []

            ports.forEach(port => {
                if (!port.complex) {
                    this.#appendRow(block, port.host, port.container, port.protocol)
                }
            })
        })
    }

    addPort(event) {
        const block = event.target.closest('[data-port-editor-target="serviceBlock"]')
        this.#appendRow(block, '', '', 'tcp')
    }

    removePort(event) {
        event.target.closest('[data-port-row]').remove()
    }

    save() {
        const data = {}

        this.serviceBlockTargets.forEach(block => {
            const serviceName = block.dataset.service
            const rows = block.querySelectorAll('[data-port-row]')
            data[serviceName] = []

            rows.forEach(row => {
                const host = row.querySelector('[data-field="host"]').value.trim()
                const container = row.querySelector('[data-field="container"]').value.trim()
                const protocol = row.querySelector('[data-field="protocol"]').value

                if (container !== '') {
                    data[serviceName].push({ host, container, protocol })
                }
            })
        })

        this.portsDataTarget.value = JSON.stringify(data)
    }

    #appendRow(block, host, container, protocol) {
        const list = block.querySelector('[data-port-list]')
        const row = document.createElement('div')
        row.setAttribute('data-port-row', '')
        row.className = 'flex items-center gap-2'

        const hostInput = this.#createInput('host', host, 'Host')
        const separator = document.createElement('span')
        separator.className = 'text-gray-400 font-medium'
        separator.textContent = ':'
        const containerInput = this.#createInput('container', container, 'Container')
        const protocolSelect = this.#createSelect(protocol)
        const removeBtn = this.#createRemoveButton()

        row.append(hostInput, separator, containerInput, protocolSelect, removeBtn)
        list.appendChild(row)
    }

    #createInput(field, value, placeholder) {
        const input = document.createElement('input')
        input.type = 'number'
        input.setAttribute('data-field', field)
        input.value = value
        input.placeholder = placeholder
        input.min = '1'
        input.max = '65535'
        input.className = 'form-input w-24 text-sm tabular-nums'
        return input
    }

    #createSelect(protocol) {
        const select = document.createElement('select')
        select.setAttribute('data-field', 'protocol')
        select.className = 'form-select w-20 text-sm'

        const tcpOption = document.createElement('option')
        tcpOption.value = 'tcp'
        tcpOption.textContent = 'TCP'
        tcpOption.selected = protocol === 'tcp'

        const udpOption = document.createElement('option')
        udpOption.value = 'udp'
        udpOption.textContent = 'UDP'
        udpOption.selected = protocol === 'udp'

        select.append(tcpOption, udpOption)
        return select
    }

    #createRemoveButton() {
        const btn = document.createElement('button')
        btn.type = 'button'
        btn.setAttribute('data-action', 'port-editor#removePort')
        btn.className = 'inline-flex items-center justify-center rounded-md p-1.5 text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors'

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
        svg.setAttribute('class', 'h-4 w-4')
        svg.setAttribute('fill', 'none')
        svg.setAttribute('viewBox', '0 0 24 24')
        svg.setAttribute('stroke-width', '1.5')
        svg.setAttribute('stroke', 'currentColor')

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path')
        path.setAttribute('stroke-linecap', 'round')
        path.setAttribute('stroke-linejoin', 'round')
        path.setAttribute('d', 'M6 18L18 6M6 6l12 12')

        svg.appendChild(path)
        btn.appendChild(svg)
        return btn
    }
}
