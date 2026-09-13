<!--
 - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 - SPDX-FileCopyrightText: 2026 Happyfeet01
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		v-if="showDialog"
		:name="t('files_zip', 'Compress files')"
		contentClasses="zip-dialog"
		@closing="handleClosing">
		<template #actions>
			<NcButton variant="primary" :disabled="!canSave" @click="saveFile">
				{{ t('files_zip', 'Compress') }}
			</NcButton>
		</template>
		<div class="zip-dialog">
			<p>{{ n('files_zip', 'Compress %n file', 'Compress %n files', nodes.length) }}</p>
			<p>{{ t('files_zip', 'The archive will be created in the background. Once finished you will receive a notification and the file is located in the current directory.') }}</p>

			<label class="format-label" for="files-zip-format">
				{{ t('files_zip', 'Archive format') }}
			</label>
			<select id="files-zip-format" v-model="format" class="format-select">
				<option v-for="option in formatOptions" :key="option.value" :value="option.value">
					{{ option.label }}
				</option>
			</select>

			<NcTextField
				ref="filenameInput"
				v-model="filename"
				:label="t('files_zip', 'Archive file name')" />

			<div v-if="supportsVolumes" class="volume-section">
				<label class="split-toggle">
					<input v-model="splitEnabled" type="checkbox">
					<span>{{ t('files_zip', 'Split into multiple parts') }}</span>
				</label>

				<div v-if="splitEnabled" class="volume-controls">
					<label class="format-label" for="files-zip-volume-size">
						{{ t('files_zip', 'Part size') }}
					</label>
					<div class="volume-row">
						<input
							id="files-zip-volume-size"
							v-model.number="volumeSizeValue"
							class="volume-input"
							type="number"
							min="1"
							step="1">
						<select v-model="volumeSizeUnit" class="volume-unit">
							<option value="MiB">MiB</option>
							<option value="GiB">GiB</option>
						</select>
					</div>
					<p class="volume-hint">
						{{ t('files_zip', 'The archive will be created as numbered parts such as {name}.001, {name}.002, …', { name: filename }) }}
					</p>
					<p v-if="!volumeSizeIsValid" class="volume-error">
						{{ t('files_zip', 'Choose a part size between 1 MiB and 1 TiB.') }}
					</p>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { ArchiveCompressionFormat, CompressionDialogResult } from './services.ts'

import { n, t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue'
import {
	ARCHIVE_CAPABILITIES,
	getArchivePath,
	MAX_VOLUME_SIZE_BYTES,
	MIN_VOLUME_SIZE_BYTES,
	replaceArchiveExtension,
} from './services.ts'

const props = defineProps<{
	nodes: INode[]
}>()

const emit = defineEmits<{
	close: [value: CompressionDialogResult | null]
}>()

const showDialog = ref(true)
const format = ref<ArchiveCompressionFormat>('zip')
const filename = ref(getArchivePath(props.nodes, format.value))
const filenameInput = useTemplateRef('filenameInput')
const splitEnabled = ref(false)
const volumeSizeValue = ref(2)
const volumeSizeUnit = ref<'MiB' | 'GiB'>('GiB')

const formatOptions = computed<Array<{ value: ArchiveCompressionFormat, label: string }>>(() => {
	const options: Array<{ value: ArchiveCompressionFormat, label: string }> = [
		{ value: 'zip', label: 'ZIP' },
	]
	if (ARCHIVE_CAPABILITIES.sevenZipAvailable) {
		options.push(
			{ value: 'tar', label: 'TAR' },
			{ value: 'tar.gz', label: 'TAR.GZ' },
			{ value: '7z', label: '7z' },
		)
	}
	return options
})

const supportsVolumes = computed(() => ARCHIVE_CAPABILITIES.sevenZipAvailable)

const volumeSizeBytes = computed<number | null>(() => {
	if (!splitEnabled.value) {
		return null
	}
	const value = Number(volumeSizeValue.value)
	if (!Number.isFinite(value) || value <= 0) {
		return null
	}
	const multiplier = volumeSizeUnit.value === 'GiB' ? 1024 * 1024 * 1024 : 1024 * 1024
	const bytes = Math.round(value * multiplier)
	return Number.isSafeInteger(bytes) ? bytes : null
})

const volumeSizeIsValid = computed(() => !splitEnabled.value
	|| (volumeSizeBytes.value !== null
		&& volumeSizeBytes.value >= MIN_VOLUME_SIZE_BYTES
		&& volumeSizeBytes.value <= MAX_VOLUME_SIZE_BYTES))

const canSave = computed(() => filename.value.trim().length > 0 && volumeSizeIsValid.value)

watch(format, (newFormat) => {
	filename.value = replaceArchiveExtension(filename.value, newFormat)
})

watch(supportsVolumes, (supported) => {
	if (!supported) {
		splitEnabled.value = false
	}
})

onMounted(() => {
	const input = filenameInput.value?.$refs?.inputField?.$refs?.input
	if (input) {
		input.setSelectionRange(0, filename.value.lastIndexOf('.'))
		input.focus()
	}
})

function saveFile(): void {
	if (!canSave.value) {
		return
	}
	showDialog.value = false
	emit('close', {
		filename: filename.value,
		format: format.value,
		volumeSize: splitEnabled.value ? volumeSizeBytes.value : null,
	})
}

function handleClosing() {
	emit('close', null)
}
</script>

<style lang="scss" scoped>
.zip-dialog {
	margin: 12px;
}

p {
	margin-bottom: 12px;
}

.format-label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.format-select,
.volume-unit,
.volume-input {
	min-height: 44px;
	padding: 0 12px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.format-select {
	width: 100%;
	margin-bottom: 12px;
}

.volume-section {
	margin-top: 16px;
}

.split-toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
}

.volume-controls {
	margin-top: 12px;
}

.volume-row {
	display: flex;
	gap: 8px;
}

.volume-input {
	width: 100%;
}

.volume-unit {
	min-width: 92px;
}

.volume-hint {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}

.volume-error {
	color: var(--color-error);
}
</style>
