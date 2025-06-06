/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node, View } from '@nextcloud/files'

import { FileAction } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import CalendarClockSvg from '@mdi/svg/svg/calendar-clock.svg?raw'

import { SET_REMINDER_MENU_ID } from './setReminderMenuAction'
import { pickCustomDate } from '../services/customPicker'

export const action = new FileAction({
	id: 'set-reminder-custom',
	displayName: () => t('files_reminders', 'Set custom reminder'),
	title: () => t('files_reminders', 'Set reminder at custom date & time'),
	iconSvgInline: () => CalendarClockSvg,

	enabled: (nodes: Node[], view: View) => {
		if (view.id === 'trashbin') {
			return false
		}
		// Only allow on a single node
		if (nodes.length !== 1) {
			return false
		}
		const node = nodes.at(0)!
		const dueDate = node.attributes['reminder-due-date']
		return dueDate !== undefined
	},

	parent: SET_REMINDER_MENU_ID,

	async exec(file: Node) {
		pickCustomDate(file)
		return null
	},

	// After presets
	order: 22,
})
