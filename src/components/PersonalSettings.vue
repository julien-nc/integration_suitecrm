<template>
	<div id="suitecrm_prefs" class="section">
		<h2>
			<a class="icon icon-suitecrm" />
			{{ t('integration_suitecrm', 'SuiteCRM integration') }}
		</h2>
		<p v-if="!showOAuth && !connected" class="settings-hint">
			{{ t('integration_suitecrm', 'To create an access token yourself, go to the "Token Access" section of your SuiteCRM profile page.') }}
			<br>
			{{ t('integration_suitecrm', 'Create a "Personal Access Token" and give it "TICKET -> AGENT", "ADMIN -> OBJECT" and "USER_PREFERENCES -> NOTIFICATIONS" permissions.') }}
		</p>
		<div id="suitecrm-content">
			<div class="suitecrm-grid-form">
				<label for="suitecrm-url">
					<a class="icon icon-link" />
					{{ t('integration_suitecrm', 'SuiteCRM instance address') }}
				</label>
				<input id="suitecrm-url"
					v-model="state.url"
					type="text"
					:disabled="connected === true"
					:placeholder="t('integration_suitecrm', 'https://my.suitecrm.org')"
					@input="onInput">
				<label v-show="!showOAuth"
					for="suitecrm-token">
					<a class="icon icon-category-auth" />
					{{ t('integration_suitecrm', 'Access token') }}
				</label>
				<input v-show="!showOAuth"
					id="suitecrm-token"
					v-model="state.token"
					type="password"
					:disabled="connected === true"
					:placeholder="t('integration_suitecrm', 'SuiteCRM access token')"
					@input="onInput">
			</div>
			<button v-if="showOAuth && !connected"
				id="suitecrm-oauth"
				:disabled="loading === true"
				:class="{ loading }"
				@click="onOAuthClick">
				<span class="icon icon-external" />
				{{ t('integration_suitecrm', 'Connect to SuiteCRM') }}
			</button>
			<div v-if="connected" class="suitecrm-grid-form">
				<label class="suitecrm-connected">
					<a class="icon icon-checkmark-color" />
					{{ t('integration_suitecrm', 'Connected as {user}', { user: state.user_name }) }}
				</label>
				<button id="suitecrm-rm-cred" @click="onLogoutClick">
					<span class="icon icon-close" />
					{{ t('integration_suitecrm', 'Disconnect from SuiteCRM') }}
				</button>
			</div>
			<div v-if="connected" id="suitecrm-search-block">
				<input
					id="search-suitecrm"
					type="checkbox"
					class="checkbox"
					:checked="state.search_enabled"
					@input="onSearchChange">
				<label for="search-suitecrm">{{ t('integration_suitecrm', 'Enable unified search for tickets') }}</label>
				<br><br>
				<p v-if="state.search_enabled" class="settings-hint">
					<span class="icon icon-details" />
					{{ t('integration_suitecrm', 'Warning, everything you type in the search bar will be sent to your SuiteCRM instance.') }}
				</p>
				<input
					id="notification-suitecrm"
					type="checkbox"
					class="checkbox"
					:checked="state.notification_enabled"
					@input="onNotificationChange">
				<label for="notification-suitecrm">{{ t('integration_suitecrm', 'Enable notifications for open tickets') }}</label>
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_suitecrm', 'user-config'),
			initialToken: loadState('integration_suitecrm', 'user-config').token,
			loading: false,
		}
	},

	computed: {
		showOAuth() {
			return this.state.url === this.state.oauth_instance_url
				&& this.state.client_id
				&& this.state.client_secret
		},
		connected() {
			return this.state.token && this.state.token !== ''
				&& this.state.url && this.state.url !== ''
				&& this.state.user_name && this.state.user_name !== ''
		},
	},

	mounted() {
		const paramString = window.location.search.substr(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const zmToken = urlParams.get('suitecrmToken')
		if (zmToken === 'success') {
			showSuccess(t('integration_suitecrm', 'Successfully connected to SuiteCRM!'))
		} else if (zmToken === 'error') {
			showError(t('integration_suitecrm', 'OAuth access token could not be obtained:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
		onLogoutClick() {
			this.state.token = ''
			this.saveOptions(true)
		},
		onNotificationChange(e) {
			this.state.notification_enabled = e.target.checked
			this.saveOptions(false)
		},
		onSearchChange(e) {
			this.state.search_enabled = e.target.checked
			this.saveOptions(false)
		},
		onInput() {
			this.loading = true
			const that = this
			delay(function() {
				that.saveOptions(true)
			}, 2000)()
		},
		saveOptions(justTokenAndUrl) {
			if (this.state.url !== '' && !this.state.url.startsWith('https://')) {
				if (this.state.url.startsWith('http://')) {
					this.state.url = this.state.url.replace('http://', 'https://')
				} else {
					this.state.url = 'https://' + this.state.url
				}
			}
			const req = {
				values: {
				},
			}
			if (justTokenAndUrl) {
				req.values = {
					token: this.state.token,
					url: this.state.url,
				}
				if (this.showOAuth) {
					req.values.token_type = 'oauth'
				} else {
					req.values.token_type = 'access'
				}
			} else {
				req.values = {
					search_enabled: this.state.search_enabled ? '1' : '0',
					notification_enabled: this.state.notification_enabled ? '1' : '0',
				}
			}
			const url = generateUrl('/apps/integration_suitecrm/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_suitecrm', 'SuiteCRM options saved'))
					if (response.data.user_name !== undefined) {
						this.state.user_name = response.data.user_name
						if (this.state.token && response.data.user_name === '') {
							showError(t('integration_suitecrm', 'Incorrect access token'))
						}
					}
				})
				.catch((error) => {
					console.debug(error)
					showError(
						t('integration_suitecrm', 'Failed to save SuiteCRM options')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
					this.loading = false
				})
		},
		onOAuthClick() {
			const redirectEndpoint = generateUrl('/apps/integration_suitecrm/oauth-redirect')
			const redirectUri = window.location.protocol + '//' + window.location.host + redirectEndpoint
			const oauthState = Math.random().toString(36).substring(3)
			const requestUrl = this.state.url + '/oauth/authorize?client_id=' + encodeURIComponent(this.state.client_id)
				+ '&redirect_uri=' + encodeURIComponent(redirectUri)
				+ '&response_type=code'
				+ '&state=' + encodeURIComponent(oauthState)

			const req = {
				values: {
					oauth_state: oauthState,
				},
			}
			const url = generateUrl('/apps/integration_suitecrm/config')
			axios.put(url, req)
				.then((response) => {
					window.location.replace(requestUrl)
				})
				.catch((error) => {
					showError(
						t('integration_suitecrm', 'Failed to save SuiteCRM OAuth state')
						+ ': ' + error.response.request.responseText
					)
				})
				.then(() => {
				})
		},
	},
}
</script>

<style scoped lang="scss">
#suitecrm-search-block {
	margin-top: 30px;
}
.suitecrm-grid-form label {
	line-height: 38px;
}
.suitecrm-grid-form input {
	width: 100%;
}
.suitecrm-grid-form {
	max-width: 600px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
	button .icon {
		margin-bottom: -1px;
	}
}
#suitecrm_prefs .icon {
	display: inline-block;
	width: 32px;
}
#suitecrm_prefs .grid-form .icon {
	margin-bottom: -3px;
}
.icon-suitecrm {
	background-image: url(./../../img/app-dark.svg);
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
}
body.theme--dark .icon-suitecrm {
	background-image: url(./../../img/app.svg);
}
#suitecrm-content {
	margin-left: 40px;
}
#suitecrm-search-block .icon {
	width: 22px;
}
</style>
