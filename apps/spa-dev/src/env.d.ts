declare namespace NodeJS {
  interface ProcessEnv {
    NODE_ENV: string;
    SPA_DEV_VERSION: string | undefined;
    VUE_ROUTER_MODE: 'hash' | 'history' | 'abstract' | undefined;
    VUE_ROUTER_BASE: string | undefined;
  }
}

declare module '*.mp3' {
  const src: string;
  export default src;
}

declare module '*.oga' {
  const src: string;
  export default src;
}

declare module '*.ogg' {
  const src: string;
  export default src;
}

declare module '*.wav' {
  const src: string;
  export default src;
}
