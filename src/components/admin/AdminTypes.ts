import type { IConfig } from '@typings';

/** System configuration */
export type ISystemConfig = {
  'memories.exiftool': string;
  'memories.exiftool_no_local': boolean;
  'memories.index.mode': string;
  'memories.index.path': string;
  'memories.index.path.blacklist': string;

  'memories.gis_type': number;

  'memories.viewer.high_res_cond_default': IConfig['high_res_cond_default'];

  'memories.vod.disable': boolean;
  'memories.vod.ffmpeg': string;
  'memories.vod.ffprobe': string;
  'memories.vod.path': string;
  'memories.vod.bind': string;
  'memories.vod.connect': string;
  'memories.vod.external': boolean;
  'memories.vod.qf': number;
  'memories.vod.tempdir': string;
  'memories.exiftool.tmp': string;
  'memories.tmp.base': string;
  'memories.clips.ffmpeg': string;
  'memories.clips.nvenc': boolean;
  'memories.clips.cpu_preset': string;
  'memories.clips.threads': number;
  'memories.clips.nice': number;
  'memories.clips.height': number;
  'memories.music.enabled': boolean;
  'memories.music.providers': string;
  'memories.music.jamendo_client_id': string;
  'memories.music.freesound_token': string;
  'memories.music.mubert_token': string;
  'memories.music.volume': number;
  'memories.video_default_quality': string;

  'memories.vod.vaapi': boolean;
  'memories.vod.vaapi.low_power': boolean;

  'memories.vod.nvenc': boolean;
  'memories.vod.nvenc.temporal_aq': boolean;
  'memories.vod.nvenc.scale': string;

  'memories.vod.use_transpose': boolean;
  'memories.vod.use_transpose.force_sw': boolean;
  'memories.vod.use_gop_size': boolean;

  'memories.db.triggers.fcu': boolean;

  enabledPreviewProviders: string[];
  preview_max_x: number;
  preview_max_y: number;
  preview_max_memory: number;
  preview_max_filesize_image: number;
};

export type IBinaryStatus = 'ok' | 'not_found' | 'not_executable' | 'test_ok' | string;

export type ISystemStatus = {
  last_index_job_start: number;
  last_index_job_duration: number;
  last_index_job_status: string;
  last_index_job_status_type: string;

  bad_encryption: boolean;
  indexed_count: number;
  failure_count: number;
  mimes: string[];
  imagick: string | false;
  gis_type: number;
  gis_count?: number;
  exiftool: IBinaryStatus;
  perl: IBinaryStatus;
  ffmpeg_preview: IBinaryStatus;
  ffmpeg: IBinaryStatus;
  ffprobe: IBinaryStatus;
  govod: IBinaryStatus;
  vaapi_dev: 'ok' | 'not_found' | 'not_readable';

  tmp_base: string;
  tmp_nc: string;
  tmp_php: string;
  tmp_upload: string;
  tmp_env: string;
  tmp_exiftool: string;
  tmp_vod: string;
  tmp_writable: boolean;
  tmp_free_gb: number;
  clips_ffmpeg_path: string;
  clips_ffmpeg: IBinaryStatus;
  clips_nvenc: 'ok' | 'off' | 'no_device' | 'no_encoder' | 'no_ffmpeg';
  clips_font: string;

  action_token: string;
};
