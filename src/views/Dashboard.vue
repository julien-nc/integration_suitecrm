<template>
	<DashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-text="title"
		:loading="state === 'loading'">
		<template #empty-content>
			<EmptyContent
				v-if="emptyContentMessage"
				:icon="emptyContentIcon">
				<template #desc>
					{{ emptyContentMessage }}
					<div v-if="state === 'no-token' || state === 'error'" class="connect-button">
						<a class="button" :href="settingsUrl">
							{{ t('integration_suitecrm', 'Connect to SuiteCRM') }}
						</a>
					</div>
				</template>
			</EmptyContent>
		</template>
	</DashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { DashboardWidget } from '@nextcloud/vue-dashboard'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import EmptyContent from '@nextcloud/vue/dist/Components/EmptyContent'

export default {
	name: 'Dashboard',

	components: {
		DashboardWidget, EmptyContent,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			suitecrmUrl: null,
			notifications: [],
			loop: null,
			state: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts'),
			themingColor: OCA.Theming ? OCA.Theming.color.replace('#', '') : '0082C9',
			windowVisibility: true,
		}
	},

	computed: {
		showMoreUrl() {
			return this.suitecrmUrl + '/index.php?module=Home&action=index'
		},
		items() {
			return this.notifications.map((n) => {
				return {
					id: this.getUniqueKey(n),
					targetUrl: this.getNotificationTarget(n),
					avatarUrl: this.getAvatarUrl(n),
					avatarUsername: this.getAuthorShortName(n),
					// overlayIconUrl: this.getNotificationTypeImage(n),
					mainText: this.getTargetTitle(n),
					subText: this.getSubline(n),
				}
			})
		},
		lastDate() {
			const nbNotif = this.notifications.length
			return (nbNotif > 0) ? this.notifications[0].date_start : null
		},
		lastMoment() {
			return moment(this.lastDate)
		},
		emptyContentMessage() {
			if (this.state === 'no-token') {
				return t('integration_suitecrm', 'No SuiteCRM account connected')
			} else if (this.state === 'error') {
				return t('integration_suitecrm', 'Error connecting to SuiteCRM')
			} else if (this.state === 'ok') {
				return t('integration_suitecrm', 'No SuiteCRM notifications!')
			}
			return ''
		},
		emptyContentIcon() {
			if (this.state === 'no-token') {
				return 'icon-suitecrm'
			} else if (this.state === 'error') {
				return 'icon-close'
			} else if (this.state === 'ok') {
				return 'icon-checkmark'
			}
			return 'icon-checkmark'
		},
	},

	watch: {
		windowVisibility(newValue) {
			if (newValue) {
				this.launchLoop()
			} else {
				this.stopLoop()
			}
		},
	},

	beforeDestroy() {
		document.removeEventListener('visibilitychange', this.changeWindowVisibility)
	},

	beforeMount() {
		this.launchLoop()
		document.addEventListener('visibilitychange', this.changeWindowVisibility)
	},

	mounted() {
	},

	methods: {
		changeWindowVisibility() {
			this.windowVisibility = !document.hidden
		},
		stopLoop() {
			clearInterval(this.loop)
		},
		async launchLoop() {
			// get suitecrm URL first
			try {
				const response = await axios.get(generateUrl('/apps/integration_suitecrm/url'))
				this.suitecrmUrl = response.data.replace(/\/+$/, '')
			} catch (error) {
				console.debug(error)
			}
			// then launch the loop
			this.fetchNotifications()
			this.loop = setInterval(() => this.fetchNotifications(), 120000)
		},
		fetchNotifications() {
			const req = {
				params: {
					eventSinceTimestamp: moment().unix(),
				},
			}
			axios.get(generateUrl('/apps/integration_suitecrm/reminders'), req).then((response) => {
				this.processNotifications(response.data)
				this.state = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.state = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_suitecrm', 'Failed to get SuiteCRM reminders'))
					this.state = 'error'
				} else {
					// there was an error in notif processing
					console.debug(error)
				}
			})
		},
		processNotifications(newNotifications) {
			// always replace reminders as one might have been added
			// in the middle of the ones we already have
			this.notifications = this.filter(newNotifications)
		},
		filter(notifications) {
			return notifications
		},
		getNotificationTarget(n) {
			return this.suitecrmUrl + '/index.php?module=' + n.attributes.related_event_module
				+ '&action=DetailView&record=' + n.attributes.related_event_module_id
		},
		getUniqueKey(n) {
			return n.id
		},
		getAuthorShortName(n) {
			return n.attributes.created_by_name
		},
		getAvatarUrl(n) {
			if (n.attributes.related_event_module === 'Calls') {
				return imagePath('integration_suitecrm', 'call.png')
			} else if (n.attributes.related_event_module === 'Meetings') {
				return imagePath('integration_suitecrm', 'meeting.png')
			}
			return ''
		},
		getNotificationTypeImage(n) {
			if (n.attributes.related_event_module === 'Calls') {
				return generateUrl('/svg/integration_suitecrm/rename?color=ffffff')
			} else if (n.attributes.related_event_module === 'Meetings') {
				return generateUrl('/svg/integration_suitecrm/add?color=ffffff')
			}
			return generateUrl('/svg/core/actions/sound?color=' + this.themingColor)
		},
		getSubline(n) {
			const mom = moment.unix(n.attributes.date_willexecute)
			const date = mom.format('L') + ' ' + mom.format('HH:mm')
			if (n.attributes.related_event_module === 'Calls') {
				return t('integration_suitecrm', 'Call at {date}', { date })
			} else if (n.attributes.related_event_module === 'Meetings') {
				return t('integration_suitecrm', 'Meeting at {date}', { date })
			}
			return ''
		},
		getTargetTitle(n) {
			return n.title
		},
	},
}
</script>

<style scoped lang="scss">
::v-deep .connect-button {
	margin-top: 10px;
}
</style>
