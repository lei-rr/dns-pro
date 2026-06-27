import { message, modal } from '../plugins/antDesignVue.js'

export function showBatchFailures(title, failures, suffix = '条') {
  if (failures.length <= 3) {
    message.warning(`${title}，失败 ${failures.length} ${suffix}：${failures.join('；')}`)
    return
  }

  modal.warning({
    title: `${title}，失败 ${failures.length} ${suffix}`,
    width: 720,
    content: Vue.h('div', { style: 'max-height: 360px; overflow: auto; white-space: pre-wrap' }, failures.join('\n')),
    okText: '知道了',
  })
}
