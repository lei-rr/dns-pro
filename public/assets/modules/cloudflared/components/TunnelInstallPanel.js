import CopyButton from '../../../shared/components/CopyButton.js'

/**
 * 安装引导面板（参考 Cloudflare 官方：选系统(Tab) → 选架构(Tab) → 分步骤命令）
 *
 * 仅接收 token（敏感数据由后端提供）；各平台安装步骤为公开产品知识，前端按所选平台/架构生成。
 */

const OS_TABS = [
  { key: 'windows', label: 'Windows' },
  { key: 'macos', label: 'macOS' },
  { key: 'debian', label: 'Debian / Ubuntu' },
  { key: 'redhat', label: 'CentOS / RHEL' },
  { key: 'docker', label: 'Docker' },
]

// 仅 Windows 按架构区分下载包；macOS/Linux 走包管理器，不分架构
const ARCH_TABS = {
  windows: [
    { key: 'amd64', label: '64 位' },
    { key: '386', label: '32 位' },
  ],
}

const RELEASE_BASE = 'https://github.com/cloudflare/cloudflared/releases/latest/download'

export default {
  name: 'TunnelInstallPanel',
  components: { CopyButton },
  props: {
    token: { type: String, default: '' },
  },
  data() {
    return { os: 'windows', arch: 'amd64' }
  },
  computed: {
    osTabs() { return OS_TABS },
    archTabs() { return ARCH_TABS[this.os] || [] },
    steps() {
      const token = this.token
      if (!token) return []

      if (this.os === 'windows') {
        const file = `cloudflared-windows-${this.arch}.msi`
        return [
          { text: `下载安装包 ${file}`, command: `${RELEASE_BASE}/${file}` },
          { text: '运行安装程序完成安装' },
          { text: '以管理员身份打开命令提示符（CMD）' },
          { text: '运行以下命令安装并启动服务：', command: `cloudflared.exe service install ${token}` },
        ]
      }

      if (this.os === 'macos') {
        return [
          { text: '安装 cloudflared：', command: 'brew install cloudflared' },
          { text: '安装为系统服务：', command: `sudo cloudflared service install ${token}` },
          { text: '或手动运行隧道：', command: `cloudflared tunnel run --token ${token}` },
        ]
      }

      if (this.os === 'debian') {
        const install = '# 添加 Cloudflare GPG key\n'
          + 'sudo mkdir -p --mode=0755 /usr/share/keyrings\n'
          + 'curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null\n'
          + '# 添加 apt 源\n'
          + "echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' | sudo tee /etc/apt/sources.list.d/cloudflared.list\n"
          + '# 安装 cloudflared\n'
          + 'sudo apt-get update && sudo apt-get install cloudflared'
        return [
          { text: '安装 cloudflared：', command: install },
          { text: '安装为系统服务：', command: `sudo cloudflared service install ${token}` },
          { text: '或手动运行隧道：', command: `cloudflared tunnel run --token ${token}` },
        ]
      }

      if (this.os === 'redhat') {
        const install = '# 添加 cloudflared.repo\n'
          + 'curl -fsSl https://pkg.cloudflare.com/cloudflared-ascii.repo | sudo tee /etc/yum.repos.d/cloudflared.repo\n'
          + '# 更新源并安装\n'
          + 'sudo yum update && sudo yum install cloudflared'
        return [
          { text: '安装 cloudflared：', command: install },
          { text: '安装为系统服务：', command: `sudo cloudflared service install ${token}` },
          { text: '或手动运行隧道：', command: `cloudflared tunnel run --token ${token}` },
        ]
      }

      // docker
      return [
        { text: '通过 Docker 运行隧道：', command: `docker run cloudflare/cloudflared:latest tunnel --no-autoupdate run --token ${token}` },
      ]
    },
  },
  watch: {
    os() {
      this.arch = this.archTabs[0]?.key || ''
    },
  },
  template: `
    <div>
      <a-tabs v-model:active-key="os" size="small">
        <a-tab-pane v-for="tab in osTabs" :key="tab.key" :tab="tab.label" />
      </a-tabs>

      <a-tabs v-if="archTabs.length" v-model:active-key="arch" size="small" type="card" style="margin-bottom: 12px">
        <a-tab-pane v-for="tab in archTabs" :key="tab.key" :tab="tab.label" />
      </a-tabs>

      <ol style="padding-left: 20px; margin: 0">
        <li v-for="(step, index) in steps" :key="index" style="margin-bottom: 12px">
          <div style="margin-bottom: 6px">{{ step.text }}</div>
          <div v-if="step.command" style="position: relative; background: #1f1f1f; color: #f0f0f0; padding: 12px 44px 12px 14px; border-radius: 6px; word-break: break-all; white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; line-height: 1.6">
            <CopyButton :value="step.command" style="position: absolute; top: 6px; right: 6px" />
            {{ step.command }}
          </div>
        </li>
      </ol>

      <a-typography-text type="secondary" style="display: block; margin-top: 12px; font-size: 12px">
        命令中已内嵌隧道凭据，请勿在公开场合分享。客户端启动后状态会自动更新为「已连接」。
      </a-typography-text>
    </div>
  `,
}
