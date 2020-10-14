<template>
	<div v-if="oAuthConfigured" id="suitecrm_prefs" class="section">
		<h2>
			<a class="icon icon-suitecrm" />
			{{ t('integration_suitecrm', 'SuiteCRM integration') }}
		</h2>
		<p v-if="!connected" class="settings-hint">
			{{ t('integration_suitecrm', 'Your login and password are not stored. They are just used once to get an access token which will be used to interact with your account.') }}
		</p>
		<div id="suitecrm-content">
			<div class="suitecrm-grid-form">
				<label for="suitecrm-url">
					<a class="icon icon-link" />
					{{ t('integration_suitecrm', 'SuiteCRM instance address') }}
				</label>
				<input id="suitecrm-url"
					v-model="state.oauth_instance_url"
					type="text"
					:disabled="true"
					:placeholder="t('integration_suitecrm', 'https://my.suitecrm.org')">
				<label v-show="!connected"
					for="suitecrm-login">
					<a class="icon icon-user" />
					{{ t('integration_suitecrm', 'User name') }}
				</label>
				<input v-show="!connected"
					id="suitecrm-login"
					v-model="login"
					type="text"
					:placeholder="t('integration_suitecrm', 'SuiteCRM login')"
					@keyup.enter="onConnect">
				<label v-show="!connected"
					for="suitecrm-password">
					<a class="icon icon-password" />
					{{ t('integration_suitecrm', 'Password') }}
				</label>
				<input v-show="!connected"
					id="suitecrm-password"
					v-model="password"
					type="password"
					:placeholder="t('integration_suitecrm', 'SuiteCRM password')"
					@keyup.enter="onConnect">
			</div>
			<button v-if="!connected"
				id="suitecrm-oauth"
				:disabled="loading === true"
				:class="{ loading }"
				@click="onConnect">
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
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_suitecrm', 'user-config'),
			login: '',
			password: '',
			loading: false,
		}
	},

	computed: {
		oAuthConfigured() {
			return this.state.oauth_instance_url && this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.oAuthConfigured && this.state.user_name && this.state.user_name !== ''
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
			this.state.user_name = ''
			this.saveOptions({ user_name: '' })
		},
		onNotificationChange(e) {
			this.state.notification_enabled = e.target.checked
			this.saveOptions({ notification_enabled: this.state.notification_enabled ? '1' : '0' })
		},
		onSearchChange(e) {
			this.state.search_enabled = e.target.checked
			this.saveOptions({ search_enabled: this.state.search_enabled ? '1' : '0' })
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_suitecrm/config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_suitecrm', 'SuiteCRM options saved'))
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
		onConnect() {
			this.loading = true
			const url = generateUrl('/apps/integration_suitecrm/oauth-connect')
			const req = {
				params: {
					login: this.login,
					password: this.password,
				},
			}
			axios.get(url, req)
				.then((response) => {
					this.state.user_name = response.data.user_name
					this.password = ''
				})
				.catch((error) => {
					console.debug(error)
					if (error.response) {
						// if (error.response.data && error.response.data.error) {
						if (error.response?.data?.error) {
							showError(
								t('integration_suitecrm', 'Failed')
								+ ': ' + error.response.data.error
							)
						} else if (error.response.request && error.response.request.responseText) {
							showError(
								t('integration_suitecrm', 'Failed')
								+ ': ' + error.response.request.responseText
							)
						}
					}
				})
				.then(() => {
					this.loading = false
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
