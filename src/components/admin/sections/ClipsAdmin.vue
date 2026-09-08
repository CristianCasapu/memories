<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>
      {{
        t(
          'memories',
          'Clips are short videos made from photos (a selection, an album, an event, or automatically). They are rendered by a worker started from the web server, or by the background job (cron). The GPU is only visible to the cron worker when PHP-FPM runs with PrivateDevices; the web worker then encodes on the CPU.',
        )
      }}
    </p>

    <template v-if="status">
      <NcNoteCard :type="binaryStatusType(status.clips_ffmpeg)">
        {{ binaryStatus('ffmpeg', status.clips_ffmpeg) }}
        <span v-if="status.clips_ffmpeg_path"> — {{ status.clips_ffmpeg_path }}</span>
      </NcNoteCard>
      <NcNoteCard :type="nvencType">{{ nvencText }}</NcNoteCard>
      <NcNoteCard :type="status.clips_font ? 'success' : 'warning'">
        {{
          status.clips_font
            ? t('memories', 'Font for titles and text lines: {font}', { font: status.clips_font })
            : t('memories', 'No bold TrueType font found (install fonts-dejavu): clips are made without text.')
        }}
      </NcNoteCard>
    </template>

    <NcTextField
      :label="t('memories', 'ffmpeg for clips (empty: the transcoding ffmpeg above)')"
      :label-visible="true"
      :model-value="config['memories.clips.ffmpeg']"
      @change="update('memories.clips.ffmpeg', $event.target.value)"
    />

    <h3>{{ t('memories', 'GPU / CPU') }}</h3>
    <NcCheckboxRadioSwitch
      :model-value="config['memories.clips.nvenc']"
      @update:model-value="update('memories.clips.nvenc', $event)"
      type="switch"
    >
      {{ t('memories', 'Encode on the NVIDIA GPU (NVENC) whenever the worker can see it; otherwise on the CPU') }}
    </NcCheckboxRadioSwitch>

    <p>{{ t('memories', 'CPU encoder speed (libx264 preset): faster means a bigger file for the same quality') }}</p>
    <NcCheckboxRadioSwitch
      v-for="p in presets"
      :key="p"
      v-model="config['memories.clips.cpu_preset']"
      :value="p"
      name="clips_preset_radio"
      type="radio"
      @update:model-value="update('memories.clips.cpu_preset')"
      >{{ p }}</NcCheckboxRadioSwitch
    >

    <NcTextField
      :label="t('memories', 'ffmpeg threads (0 = automatic, all cores)')"
      :label-visible="true"
      :model-value="String(config['memories.clips.threads'])"
      @change="update('memories.clips.threads', clamp($event.target.value, 0, 64))"
    />
    <NcTextField
      :label="t('memories', 'Worker priority (nice: 0 = normal, 19 = lowest, stays out of the way of the web server)')"
      :label-visible="true"
      :model-value="String(config['memories.clips.nice'])"
      @change="update('memories.clips.nice', clamp($event.target.value, 0, 19))"
    />

    <h3>{{ t('memories', 'Resolution') }}</h3>
    <NcCheckboxRadioSwitch
      v-model="config['memories.clips.height']"
      :value="1080"
      name="clips_height_radio"
      type="radio"
      @update:model-value="update('memories.clips.height', 1080)"
      >{{ t('memories', '1080p (1920 × 1080)') }}</NcCheckboxRadioSwitch
    >
    <NcCheckboxRadioSwitch
      v-model="config['memories.clips.height']"
      :value="720"
      name="clips_height_radio"
      type="radio"
      @update:model-value="update('memories.clips.height', 720)"
      >{{ t('memories', '720p (1280 × 720, faster, smaller files)') }}</NcCheckboxRadioSwitch
    >
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'ClipsAdmin',
  title: t('memories', 'Clips (videos from photos)'),
  mixins: [AdminMixin],

  data: () => ({
    presets: ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow'],
  }),

  computed: {
    nvencType(): 'success' | 'warning' | 'error' {
      const s = this.status?.clips_nvenc;
      if (s === 'ok') return 'success';
      if (s === 'off' || s === 'no_device') return 'warning';
      return 'error';
    },

    nvencText(): string {
      switch (this.status?.clips_nvenc) {
        case 'ok':
          return this.t('memories', 'NVENC is available to the web server (and to cron).');
        case 'off':
          return this.t('memories', 'GPU encoding is switched off: every clip is encoded on the CPU.');
        case 'no_device':
          return this.t(
            'memories',
            'The GPU is not visible from the web server (/dev/nvidia0 not readable — PrivateDevices). Clips started here are encoded on the CPU; clips made by cron (automatic clips, the queue job) use NVENC if the driver has libnvidia-encode.',
          );
        case 'no_encoder':
          return this.t('memories', 'This ffmpeg has no h264_nvenc encoder: clips are encoded on the CPU.');
        default:
          return this.t('memories', 'No usable ffmpeg: clips cannot be made.');
      }
    },
  },

  methods: {
    clamp(value: string, min: number, max: number): number {
      const n = Math.round(Number(value));
      if (!Number.isFinite(n)) return min;
      return Math.max(min, Math.min(max, n));
    },
  },
});
</script>
