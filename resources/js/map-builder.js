import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import MapBuilder from './components/map-builder/MapBuilder.vue';

const app = createApp(MapBuilder);
const pinia = createPinia();

app.use(pinia);
app.mount('#map-builder-app');
