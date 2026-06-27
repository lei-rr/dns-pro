export const antDesignVue = window.antd || window.AntDesignVue

if (!antDesignVue) {
  throw new Error('Ant Design Vue is not loaded')
}

export const message = antDesignVue.message
export const modal = antDesignVue.Modal
export const notification = antDesignVue.notification

// 让全局 message / notification 出现在导航栏下方，避免遮挡 sticky header（header 高度 64px）
message.config?.({
  top: '80px',
  duration: 3,
  maxCount: 5,
})

notification?.config?.({
  top: '80px',
  duration: 3,
})
