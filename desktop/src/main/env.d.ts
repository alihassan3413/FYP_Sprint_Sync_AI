/// <reference types="electron-vite/node" />

interface ImportMetaEnv {
    /** SprintSync's address for this build (https in production). */
    readonly MAIN_VITE_SPRINTSYNC_URL?: string;
    /** "1" only for local UAT builds that talk to a QA server on this computer over http. */
    readonly MAIN_VITE_SPRINTSYNC_LOCAL_UAT?: string;
    /** "stable" or "uat" (the default). */
    readonly MAIN_VITE_SPRINTSYNC_CHANNEL?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
