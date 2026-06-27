export default {
  props: {
    items: { type: Array, default: () => [] },
  },
  emits: ['edit', 'select'],
  methods: {
    select(item) {
      if (!item.disabled) this.$emit('select', item.key)
    },
  },
  template: `
    <a-space size="small">
      <a-button type="link" size="small" @click="$emit('edit')">编辑</a-button>
      <a-dropdown v-if="items.length">
        <a-button type="link" size="small">更多</a-button>
        <template #overlay>
          <a-menu>
            <a-menu-item
              v-for="item in items"
              :key="item.key"
              :danger="!!item.danger"
              :disabled="!!item.disabled"
              @click="select(item)"
            >{{ item.label }}</a-menu-item>
          </a-menu>
        </template>
      </a-dropdown>
    </a-space>
  `,
}
