<template>
	<div id="suitecrm_prefs" class="section">
		<h2>
			<a class="icon icon-suitecrm" />
			{{ t('integration_suitecrm', 'SuiteCRM integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_suitecrm', 'If you want to allow your Nextcloud users to use OAuth to authenticate to a SuiteCRM instance, create a "new password client" in your SuiteCRM admin settings ("OAuth2 Clients and Tokens" section) and put the client ID and secret below.') }}
			<br><br>
			<span class="icon icon-details" />
			{{ t('integration_suitecrm', 'Make sure you created private and public keys for your SuiteCRM instance. Authentication won\'t work if those keys are missing.') }}
			<a href="https://docs.suitecrm.com/developer/api/developer-setup-guide/json-api/#_generate_private_and_public_key_for_oauth2" target="_blank" class="external">
				<span class="icon icon-external" />
				{{ t('integration_suitecrm', 'SuiteCRM OAuth2 documentation') }}
			</a>
		</p>
		<div class="grid-form">
			<label for="suitecrm-oauth-instance">
				<a class="icon icon-link" />
				{{ t('integration_suitecrm', 'SuiteCRM instance address') }}
			</label>
			<input id="suitecrm-oauth-instance"
				v-model="state.oauth_instance_url"
				type="text"
				:placeholder="t('integration_suitecrm', 'SuiteCRM address')"
				@input="onInput">
			<label for="suitecrm-client-id">
				<a class="icon icon-category-auth" />
				{{ t('integration_suitecrm', 'Application ID') }}
			</label>
			<input id="suitecrm-client-id"
				v-model="state.client_id"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_suitecrm', 'ID of your application')"
				@focus="readonly = false"
				@input="onInput">
			<label for="suitecrm-client-secret">
				<a class="icon icon-category-auth" />
				{{ t('integration_suitecrm', 'Application secret') }}
			</label>
			<input id="suitecrm-client-secret"
				v-model="state.client_secret"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_suitecrm', 'Client secret of your application')"
				@focus="readonly = false"
				@input="onInput">
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
	name: 'AdminSettings',

	components: {
	},

	props: [],

	data() {
		return {
			state: loadState('integration_suitecrm', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
		}
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		onInput() {
			const that = this
			delay(() => {
				that.saveOptions()
			}, 2000)()
		},
		saveOptions() {
			const req = {
				values: {
					client_id: this.state.client_id,
					client_secret: this.state.client_secret,
					oauth_instance_url: this.state.oauth_instance_url,
				},
			}
			const url = generateUrl('/apps/integration_suitecrm/admin-config')
			axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_suitecrm', 'SuiteCRM admin options saved'))
				})
				.catch((error) => {
					showError(
						t('integration_suitecrm', 'Failed to save SuiteCRM admin options')
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
#suitecrm_prefs {
	.icon {
		display: inline-block;
		width: 32px;
	}

	.icon-external {
		width: 15px;
		margin-bottom: -3px;
	}

	.grid-form {
		max-width: 500px;
		display: grid;
		grid-template: 1fr / 1fr 1fr;
		margin-left: 30px;
		label {
			line-height: 38px;
		}
		input {
			width: 100%;
		}
		.icon {
			margin-bottom: -3px;
		}
	}

	.icon-suitecrm {
		background-image: url(./../../img/app-dark.svg);
		background-size: 23px 23px;
		height: 23px;
		margin-bottom: -4px;
	}
}

body.theme--dark .icon-suitecrm {
	background-image: url(./../../img/app.svg);
}
</style>
