<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>
      {{
        t(
          'memories',
          'Videos made from photos ("Create a video") get background music. The mood is read from the pictures with the CLIP model of the Recognize fork (party, birthday, wedding, melancholic, playful, summer, calm, winter, travel, sport, romantic, family) and a matching track comes from the first provider below that has one. The credit is written into the video file.',
        )
      }}
    </p>

    <NcCheckboxRadioSwitch
      :model-value="config['memories.music.enabled']"
      @update:model-value="update('memories.music.enabled', $event)"
      type="switch"
    >
      {{ t('memories', 'Add background music to videos') }}
    </NcCheckboxRadioSwitch>

    <NcNoteCard :type="musicStatus.moodDetection ? 'success' : 'warning'" v-if="musicStatus">
      {{
        musicStatus.moodDetection
          ? t('memories', 'Mood detection is available (natural-language search of the Recognize fork).')
          : t(
              'memories',
              'Mood detection needs the natural-language search of the Recognize fork (Administration › Recognize › Natural-language search); until then "Automatic" uses calm music.',
            )
      }}
    </NcNoteCard>

    <h3>{{ t('memories', 'Providers') }}</h3>
    <p>
      {{
        t(
          'memories',
          'Jamendo: free and royalty-free independent music under Creative Commons (a free client id from developer.jamendo.com). Freesound: sounds, loops and short pieces, CC0 and CC-BY (an API token from freesound.org/apiv2/apply). Mubert: music generated on demand for the exact length and mood, never claimed by copyright (a paid B2B token). Epidemic Sound and Artlist have no public API; their enterprise contracts would need their own integration.',
        )
      }}
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

    <h3>{{ t('memories', 'Videos in the making (all users)') }}</h3>
    <p>
      {{
        t(
          'memories',
          'Every video asked for, with its progress and outcome. A video is made by a worker started right away, or by the background job within five minutes; one that stays "running" for half an hour is marked failed.',
        )
      }}
    </p>
    <div class="jobs-bar">
      <NcButton @click="loadJobs">{{ t('memories', 'Refresh') }}</NcButton>
      <span class="muted" v-if="jobs">{{ t('memories', '{n} entries', { n: jobs.length }) }}</span>
    </div>
    <table class="jobs" v-if="jobs && jobs.length">
      <thead>
        <tr>
          <th>{{ t('memories', 'User') }}</th>
          <th>{{ t('memories', 'Video') }}</th>
          <th>{{ t('memories', 'Status') }}</th>
          <th>{{ t('memories', 'Details') }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="job in jobs" :key="job.id" :class="job.status">
          <td>{{ job.uid }}</td>
          <td>
            {{ job.result_name || job.title || t('memories', '{n} photos', { n: job.photos }) }}<br /><small
              class="muted"
              >{{ job.result_folder }}</small
            >
          </td>
          <td>
            {{ job.status }}<span v-if="job.status === 'running'"> {{ job.progress }}%</span><br /><small
              class="muted"
              >{{ new Date((job.finished || job.created) * 1000).toLocaleString() }}</small
            >
          </td>
          <td>
            <span v-if="job.status === 'failed'" class="error">{{ job.error }}</span>
            <span v-else-if="job.status === 'running' || job.status === 'queued'">{{ job.step }}</span>
            <span v-else-if="job.track && job.track.credit">{{ job.track.credit }}</span>
            <span v-else-if="job.mood">{{ job.mood }}</span>
          </td>
          <td class="job-actions">
            <NcButton
              v-if="job.status === 'queued' || job.status === 'running'"
              @click="jobAct(job, 'cancel')"
              variant="tertiary"
              >{{ t('memories', 'Cancel') }}</NcButton
            >
            <NcButton
              v-if="job.status === 'failed' || job.status === 'cancelled'"
              @click="jobAct(job, 'retry')"
              variant="tertiary"
              >{{ t('memories', 'Retry') }}</NcButton
            >
            <NcButton v-if="job.status !== 'running'" @click="jobAct(job, 'delete')" variant="tertiary">{{
              t('memories', 'Remove')
            }}</NcButton>
          </td>
        </tr>
      </tbody>
    </table>

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
type IVideoJob = {
  id: number;
  uid: string;
  status: string;
  title: string;
  photos: number;
  mood: string;
  progress: number;
  step: string;
  result_name: string;
  result_folder: string;
  track: { credit?: string } | null;
  error: string | null;
  created: number;
  finished: number;
};

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
    jobs: null as IVideoJob[] | null,
    jobsTimer: null as number | null,
  }),

  async mounted() {
    try {
      this.musicStatus = (await axios.get<IMusicStatus>(API.MUSIC_STATUS())).data;
    } catch (e) {
      console.warn(e);
    }
    await this.loadJobs();
  },

  beforeUnmount() {
    if (this.jobsTimer) window.clearTimeout(this.jobsTimer);
  },

  methods: {
    async loadJobs() {
      try {
        this.jobs = (await axios.get<IVideoJob[]>(generateUrl('/apps/memories/api/admin/videos/jobs'))).data;
      } catch (e) {
        console.warn(e);
      }
      if (this.jobsTimer) window.clearTimeout(this.jobsTimer);
      if (this.jobs?.some((j) => j.status === 'queued' || j.status === 'running')) {
        this.jobsTimer = window.setTimeout(() => this.loadJobs(), 3000);
      }
    },

    async jobAct(job: IVideoJob, what: 'cancel' | 'retry' | 'delete') {
      try {
        if (what === 'delete') await axios.delete(generateUrl(`/apps/memories/api/admin/videos/jobs/${job.id}`));
        else await axios.post(generateUrl(`/apps/memories/api/admin/videos/jobs/${job.id}/${what}`), {});
      } catch (error: any) {
        console.error(error);
      }
      await this.loadJobs();
    },

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
.jobs-bar {
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 6px 0;
}
table.jobs {
  width: 100%;
  border-collapse: collapse;
  margin: 6px 0 14px;
  font-size: 0.92em;
  th,
  td {
    text-align: left;
    padding: 6px 8px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
  }
  tr.failed td:nth-child(3) {
    color: var(--color-error);
  }
  tr.done td:nth-child(3) {
    color: var(--color-success);
  }
  .error {
    color: var(--color-error);
  }
  .job-actions {
    white-space: nowrap;
  }
}
.muted {
  color: var(--color-text-maxcontrast);
}
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
