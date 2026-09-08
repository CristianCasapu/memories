<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>
      {{ t('memories', 'Videos made from photos ("Create a video") get background music. The mood is read from the pictures with the CLIP model of the Recognize fork (party, birthday, wedding, melancholic, playful, summer, calm, winter, travel, sport, romantic, family) and a matching track comes from the first provider below that has one. The credit is written into the video file.') }}
    </p>

    <NcCheckboxRadioSwitch
      :model-value="config['memories.music.enabled']"
      @update:model-value="update('memories.music.enabled', $event)"
      type="switch"
    >
      {{ t('memories', 'Add background music to videos') }}
    </NcCheckboxRadioSwitch>

    <NcNoteCard :type="musicStatus.moodDetection ? 'success' : 'warning'" v-if="musicStatus">
      {{ musicStatus.moodDetection
        ? t('memories', 'Mood detection is available (natural-language search of the Recognize fork).')
        : t('memories', 'Mood detection needs the natural-language search of the Recognize fork (Administration › Recognize › Natural-language search); until then "Automatic" uses calm music.') }}
    </NcNoteCard>

    <h3>{{ t('memories', 'Providers') }}</h3>
    <p>
      {{ t('memories', 'Jamendo: free and royalty-free independent music under Creative Commons (a free client id from developer.jamendo.com). Freesound: sounds, loops and short pieces, CC0 and CC-BY (an API token from freesound.org/apiv2/apply). Mubert: music generated on demand for the exact length and mood, never claimed by copyright (a paid B2B token). Epidemic Sound and Artlist have no public API; their enterprise contracts would need their own integration.') }}
    </p>

    <NcTextField
      :label="t('memories', 'Jamendo client id')"
      :label-visible="true"
      :model-value="config['memories.music.jamendo_client_id']"
      @change="update('memories.music.jamendo_client_id', $event.target.value)"
    />
    <NcTextField
      :label="t('memories', 'Freesound API token')"
      :label-visible="true"
      :model-value="config['memories.music.freesound_token']"
      @change="update('memories.music.freesound_token', $event.target.value)"
    />
    <NcTextField
      :label="t('memories', 'Mubert token (pat)')"
      :label-visible="true"
      :model-value="config['memories.music.mubert_token']"
      @change="update('memories.music.mubert_token', $event.target.value)"
    />
    <NcTextField
      :label="t('memories', 'Order of the providers (comma separated: jamendo, freesound, mubert)')"
      :label-visible="true"
      :model-value="config['memories.music.providers']"
      @change="update('memories.music.providers', $event.target.value)"
    />
    <NcTextField
      :label="t('memories', 'Music volume under the video (0.1 – 1)')"
      :label-visible="true"
      :model-value="String(config['memories.music.volume'])"
      @change="update('memories.music.volume', Number($event.target.value))"
    />

    <h3>{{ t('memories', 'Try it') }}</h3>
    <div class="try">
      <select v-model="tryMood">
        <option v-for="(label, id) in musicStatus?.moods ?? {}" :key="id" :value="id">{{ label }}</option>
      </select>
      <NcButton :disabled="busy" @click="tryIt">{{ t('memories', 'Find a track') }}</NcButton>
      <code v-if="tryResult">{{ tryResult }}</code>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

import NcButton from '@nextcloud/vue/components/NcButton';

import { translate as t } from '@services/l10n';
import { API } from '@services/API';

import AdminMixin from '../AdminMixin';

type IMusicStatus = { enabled: boolean; providers: string[]; moodDetection: boolean; moods: Record<string, string> };

export default defineComponent({
  name: 'Music',
  title: t('memories', 'Music for videos'),
  components: { NcButton },
  mixins: [AdminMixin],

  data: () => ({
    musicStatus: null as IMusicStatus | null,
    tryMood: 'party',
    tryResult: '',
    busy: false,
  }),

  async mounted() {
    try {
      this.musicStatus = (await axios.get<IMusicStatus>(API.MUSIC_STATUS())).data;
    } catch (e) {
      console.warn(e);
    }
  },

  methods: {
    async tryIt() {
      this.busy = true;
      this.tryResult = '…';
      try {
        const res = await axios.post(generateUrl('/apps/memories/api/admin/music-test'), { mood: this.tryMood });
        this.tryResult = (res.data.ok ? '✓ ' : '✗ ') + res.data.message;
      } catch (error: any) {
        this.tryResult = error?.response?.data?.message || String(error);
      } finally {
        this.busy = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.try {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  select {
    padding: 6px 10px;
    border-radius: 8px;
    border: 2px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
  }
  code {
    flex-basis: 100%;
    white-space: pre-wrap;
  }
}
</style>
