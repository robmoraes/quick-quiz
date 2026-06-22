<template>
  <q-layout view="hHh lpR fFf" class="app-shell">
    <q-page-container class="layout-frame">
      <div class="game-grid">
        <aside class="ad-slot ad-slot--side ad-slot--left">
          <LayoutAds placement="side" />
        </aside>
        <main class="game-main">
          <router-view />
        </main>
        <aside class="ad-slot ad-slot--side ad-slot--right">
          <LayoutAds placement="emphasis" />
        </aside>
      </div>
      <div class="ad-slot ad-slot--bottom">
        <LayoutTailBanner />
      </div>
      <div class="app-version" aria-label="Application version">v{{ appVersion }}</div>
    </q-page-container>
  </q-layout>
</template>

<script setup lang="ts">
import LayoutAds from 'src/components/quiz/LayoutAds.vue';
import LayoutTailBanner from 'src/components/quiz/LayoutTailBanner.vue';
import { APP_VERSION } from 'src/services/app-version';

const appVersion = APP_VERSION;
</script>
