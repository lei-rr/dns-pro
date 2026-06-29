import { authApi } from '../api/auth.js'
import { message } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'

export default {
  data() {
    return { username: '', password: '', captcha: '', captchaRequired: false, captchaUrl: '', loading: false }
  },
  async mounted() {
    try {
      const response = await authApi.me()
      this.captchaRequired = !!response.data?.captcha_required
      if (this.captchaRequired) this.refreshCaptcha()
    } catch (error) {
      if (error.details?.captcha_required) {
        this.captchaRequired = true
        this.refreshCaptcha()
      }
    }
  },
  methods: {
    refreshCaptcha() {
      this.captchaUrl = authApi.captchaUrl()
    },
    async submit() {
      const username = this.username.trim()
      const password = this.password
      const captcha = this.captcha.trim()
      if (!username || !password || this.loading) return
      if (this.captchaRequired && !captcha) return
      if (/\s/.test(password)) {
        message.warning('密码不能包含空格')
        return
      }

      this.loading = true
      try {
        await authApi.login(username, password, this.captchaRequired ? captcha : undefined)
        this.$router.replace('/')
      } catch (error) {
        if (error.details?.captcha_required || error.code === 'captcha_required' || error.code === 'invalid_captcha') {
          this.captchaRequired = true
          this.captcha = ''
          this.refreshCaptcha()
        }
        message.error(errorMessage(error))
      } finally {
        this.loading = false
      }
    },
  },
  template: `
    <a-layout style="min-height: 100vh; background: #fff">
      <a-layout-content style="display: flex; align-items: center; justify-content: center; padding: 24px">
        <div style="width: min(360px, calc(100vw - 32px))">
          <div style="display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 30px">
            <a-avatar shape="square" size="large" style="background: #1677ff">D</a-avatar>
            <div>
              <a-typography-title :level="3" style="margin: 0; line-height: 1">DNS-PRO</a-typography-title>
            </div>
          </div>
          <a-form layout="vertical" @submit.prevent="submit">
            <a-form-item style="margin-bottom: 18px">
              <a-input v-model:value="username" size="large" placeholder="请输入用户名" autocomplete="username" @pressEnter="submit" />
            </a-form-item>
            <a-form-item style="margin-bottom: 18px">
              <a-input-password v-model:value="password" size="large" placeholder="请输入密码" autocomplete="current-password" @pressEnter="submit" />
            </a-form-item>
            <a-form-item v-if="captchaRequired" style="margin-bottom: 18px">
              <a-input v-model:value="captcha" size="large" placeholder="请输入验证码" autocomplete="off" @pressEnter="submit">
                <template #suffix>
                  <img :src="captchaUrl" alt="验证码" title="点击刷新验证码" style="height: 32px; cursor: pointer" @click="refreshCaptcha" />
                </template>
              </a-input>
            </a-form-item>
            <a-button type="primary" size="large" block :loading="loading" :disabled="!username.trim() || !password || (captchaRequired && !captcha.trim())" @click="submit">登录</a-button>
          </a-form>
        </div>
      </a-layout-content>
    </a-layout>
  `,
}
