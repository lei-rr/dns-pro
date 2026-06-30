export default {
  props: {
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    backText: { type: String, default: '' },
    keyword: { type: String, default: '' },
    searchPlaceholder: { type: String, default: '' },
    showSearch: { type: Boolean, default: true },
    searchWidth: { type: String, default: '' },
  },
  emits: ['update:keyword', 'search', 'back'],
  template: `
    <div class="page-toolbar">
      <div>
        <a-button v-if="backText" type="link" style="padding: 0" @click="$emit('back')">{{ backText }}</a-button>
        <a-typography-title :level="3" :style="backText ? { margin: '4px 0' } : { marginBottom: '4px' }">{{ title }}</a-typography-title>
        <a-typography-text v-if="subtitle" type="secondary">{{ subtitle }}</a-typography-text>
      </div>
      <div class="page-actions">
        <a-input-search
          v-if="showSearch"
          :value="keyword"
          :style="searchWidth ? { width: searchWidth } : null"
          :placeholder="searchPlaceholder"
          allow-clear
          @update:value="$emit('update:keyword', $event)"
          @search="$emit('search')"
        />
        <slot name="actions" />
      </div>
    </div>
  `,
}
