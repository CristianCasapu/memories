<template>
  <div class="people-review">
    <div class="header">
      <NcActions>
        <NcActionButton :aria-label="t('memories', 'Back')" @click="$router.go(-1)">
          {{ t('memories', 'Back') }}
          <template #icon> <BackIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
      <span class="title">{{ t('memories', 'Review unnamed people') }}</span>
      <div class="right">
        <NcButton variant="secondary" :disabled="busy" @click="track">
          <template #icon> <TrackIcon :size="20" /> </template>
          {{ t('memories', 'Find people in bursts') }}
        </NcButton>
        <NcButton variant="tertiary" :disabled="busy" @click="refresh">
          <template #icon> <RefreshIcon :size="20" /> </template>
        </NcButton>
      </div>
    </div>

    <div class="summary" v-if="data">
      {{
        t('memories', '{n} unnamed people with at least 2 photos · {u} unassigned faces · {i} ignored', {
          n: data.people.length,
          u: data.unassigned,
          i: data.ignored,
        })
      }}
    </div>
    <div class="summary" v-else-if="loading">{{ t('memories', 'Loading …') }}</div>

    <datalist id="memories-review-names">
      <option v-for="p in data?.named ?? []" :key="p.cluster_id" :value="p.title" />
    </datalist>

    <div class="row" v-for="person in data?.people ?? []" :key="person.cluster_id">
      <div class="faces">
        <div
          v-for="face in person.faces"
          :key="face.id"
          class="face"
          :style="cropStyle(face)"
          :title="t('memories', 'Open photo')"
          @click="openPhoto(face.file_id)"
        />
      </div>
      <div class="info">
        <div class="count">
          <a href="#" @click.prevent="openPerson(person.cluster_id)">
            {{ n('memories', '%n photo', '%n photos', person.count) }}
          </a>
        </div>
        <div class="suggestion" v-if="person.suggestion">
          <span v-if="person.suggestion.shared_files > 0" class="veto">
            {{
              t('memories', 'Looks like {name} (distance {d}) but appears together with them in {n} photo(s), so it is someone else', {
                name: person.suggestion.title,
                d: person.suggestion.distance,
                n: person.suggestion.shared_files,
              })
            }}
          </span>
          <span v-else>
            {{ t('memories', 'Might be {name} (distance {d})', { name: person.suggestion.title, d: person.suggestion.distance }) }}
            <NcButton variant="primary" :disabled="busy" @click="merge(person, person.suggestion.cluster_id, person.suggestion.title)">
              {{ t('memories', 'It is {name}', { name: person.suggestion.title }) }}
            </NcButton>
          </span>
        </div>
        <div class="actions">
          <NcTextField
            v-model="names[person.cluster_id]"
            :label="t('memories', 'Name')"
            :placeholder="t('memories', 'Name this person')"
            list="memories-review-names"
            @keydown.enter="rename(person)"
          />
          <NcButton variant="secondary" :disabled="busy || !(names[person.cluster_id] || '').trim()" @click="rename(person)">
            {{ t('memories', 'Name') }}
          </NcButton>
          <NcButton variant="tertiary" :disabled="busy" @click="ignore(person)">
            {{ t('memories', 'Not a person') }}
          </NcButton>
        </div>
      </div>
    </div>

    <div class="summary" v-if="data && !data.people.length">
      {{ t('memories', 'Every person with at least 2 photos has a name.') }}
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError, showSuccess } from '@nextcloud/dialogs';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import { API } from '@services/API';
import * as utils from '@services/utils';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';
import TrackIcon from 'vue-material-design-icons/AccountMultiplePlus.vue';

import type { IPhoto } from '@typings';

type ISampleFace = { id: number; file_id: number; x: number; y: number; width: number; height: number };
type ISuggestion = {
  cluster_id: number;
  title: string;
  distance: number;
  shared_files: number;
  second_title: string | null;
  second_distance: number | null;
  confident: boolean;
};
type IReviewPerson = { cluster_id: number; count: number; faces: ISampleFace[]; suggestion: ISuggestion | null };
type IReview = {
  people: IReviewPerson[];
  named: { cluster_id: number; title: string; count: number }[];
  unassigned: number;
  ignored: number;
};

/** Triage of unnamed people: name, merge into the suggested person, or ignore. */
export default defineComponent({
  name: 'PeopleReview',
  components: {
    NcActions,
    NcActionButton,
    NcButton,
    NcTextField,
    BackIcon,
    RefreshIcon,
    TrackIcon,
  },

  data: () => ({
    data: null as IReview | null,
    names: {} as Record<number, string>,
    loading: false,
    busy: false,
  }),

  mounted() {
    this.refresh();
  },

  methods: {
    async refresh() {
      this.loading = true;
      try {
        const res = await axios.get(API.RECOGNIZE_REVIEW());
        this.data = res.data as IReview;
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not load the people (is the CristianCasapu Recognize fork installed?)'));
      } finally {
        this.loading = false;
      }
    },

    cropStyle(face: ISampleFace) {
      const url = utils.getPreviewUrl({ photo: { fileid: face.file_id } as IPhoto, size: 512 });
      const w = Math.min(0.999, Math.max(0.01, face.width));
      const h = Math.min(0.999, Math.max(0.01, face.height));
      return {
        backgroundImage: `url(${url})`,
        backgroundSize: `${100 / w}% ${100 / h}%`,
        backgroundPosition: `${(face.x / (1 - w)) * 100}% ${(face.y / (1 - h)) * 100}%`,
      };
    },

    openPerson(clusterId: number) {
      this.$router.push({ name: 'recognize', params: { user: utils.uid as string, name: String(clusterId) } });
    },

    openPhoto(fileId: number) {
      window.open(generateUrl('/f/{fileId}', { fileId }), '_blank');
    },

    remove(person: IReviewPerson) {
      if (!this.data) return;
      this.data.people = this.data.people.filter((p) => p.cluster_id !== person.cluster_id);
    },

    async rename(person: IReviewPerson) {
      const title = (this.names[person.cluster_id] || '').trim();
      if (!title || this.busy) return;
      this.busy = true;
      try {
        const res = await axios.put(API.RECOGNIZE_CLUSTER(person.cluster_id), { title });
        showSuccess(
          res.data.merged
            ? this.t('memories', 'Merged into {name} ({n} photos)', { name: res.data.title, n: res.data.moved })
            : this.t('memories', 'Person named {name}', { name: res.data.title }),
        );
        this.remove(person);
        if (!res.data.merged && this.data) this.data.named.push({ cluster_id: res.data.cluster_id, title: res.data.title, count: person.count });
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'Could not name the person'));
      } finally {
        this.busy = false;
      }
    },

    async merge(person: IReviewPerson, targetId: number, targetTitle: string) {
      if (this.busy) return;
      this.busy = true;
      try {
        const res = await axios.post(API.RECOGNIZE_MERGE(person.cluster_id, targetId));
        showSuccess(this.t('memories', 'Merged into {name} ({n} photos)', { name: targetTitle, n: res.data.moved }));
        this.remove(person);
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'Could not merge the people'));
      } finally {
        this.busy = false;
      }
    },

    async ignore(person: IReviewPerson) {
      if (this.busy) return;
      if (
        !(await utils.confirmDestructive({
          title: this.t('memories', 'Not a person'),
          message: this.t('memories', 'Hide these {n} faces for good? (statues, posters, screens …)', { n: person.count }),
          confirm: this.t('memories', 'Hide'),
          confirmClasses: 'error',
          cancel: this.t('memories', 'Cancel'),
        }))
      ) {
        return;
      }
      this.busy = true;
      try {
        await axios.post(API.RECOGNIZE_IGNORE_CLUSTER(person.cluster_id));
        this.remove(person);
        if (this.data) this.data.ignored += person.count;
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'Could not ignore the person'));
      } finally {
        this.busy = false;
      }
    },

    async track() {
      if (this.busy) return;
      this.busy = true;
      try {
        const res = await axios.post(API.RECOGNIZE_TRACK());
        showSuccess(this.t('memories', '{n} faces assigned from neighbouring frames', { n: res.data.assigned }));
        await this.refresh();
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'Face tracking failed'));
      } finally {
        this.busy = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.people-review {
  padding: 8px 12px 40px;
  max-width: 1100px;
  overflow-y: auto;
  height: 100%;

  .header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;

    .title {
      font-size: 1.2em;
      font-weight: 500;
      flex: 1;
    }
    .right {
      display: flex;
      gap: 6px;
    }
  }

  .summary {
    color: var(--color-text-maxcontrast);
    margin: 6px 0 12px;
  }

  .row {
    display: flex;
    gap: 14px;
    padding: 10px 0;
    border-top: 1px solid var(--color-border);
    flex-wrap: wrap;

    .faces {
      display: flex;
      gap: 4px;
      flex-wrap: wrap;
      max-width: 240px;

      .face {
        width: 72px;
        height: 72px;
        border-radius: 8px;
        background-color: var(--color-background-dark);
        background-repeat: no-repeat;
        cursor: pointer;
      }
    }

    .info {
      flex: 1;
      min-width: 260px;
      display: flex;
      flex-direction: column;
      gap: 6px;

      .count a {
        font-weight: 500;
        text-decoration: underline;
      }
      .suggestion {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;

        .veto {
          color: var(--color-text-maxcontrast);
        }
      }
      .actions {
        display: flex;
        gap: 6px;
        align-items: flex-end;
        flex-wrap: wrap;

        > :first-child {
          max-width: 280px;
        }
      }
    }
  }
}
</style>
