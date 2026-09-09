declare module '@typings' {
  /** A handful of photos shown one after the other, full screen. */
  export type IStory = {
    /** Database ID */
    id: number;
    /** What the story is called */
    title: string;
    /** Date, place, or whatever else describes it */
    subtitle: string;
    /** Where it came from: manual, thisday, week, event, person */
    kind: 'manual' | 'thisday' | 'week' | 'event' | 'person' | string;
    /** File ID of the photo on the cover */
    cover: number;
    /** Etag of the cover, so the browser does not keep an old thumbnail */
    cover_etag: string;
    /** When the story was put together (epoch seconds) */
    created: number;
    /** File ID where the person stopped watching (0 = never opened, -1 = watched to the end) */
    seen: number;
    /** How many photos are in it */
    count: number;
  };
}
