export default {
  props: {
    count: { type: Number, default: 0 },
    deleting: Boolean,
    deleteText: { type: String, default: '批量删除' },
  },
  emits: ['delete', 'clear'],
  template: `
    <div v-if="count" class="batch-toolbar" aria-live="polite">
      <span>已选择 {{ count }} 项</span>
      <a-space size="small">
        <a-button size="small" danger :loading="deleting" @click="$emit('delete')">{{ deleteText }}</a-button>
        <a-button size="small" @click="$emit('clear')">取消选择</a-button>
      </a-space>
    </div>
  `,
}
