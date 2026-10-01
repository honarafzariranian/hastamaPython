import { createApp } from 'vue';
import { createPinia } from 'pinia';

import AppLayout from '@/layouts/AppLayout.vue';
import router from '@/router';
import { initTheme } from '@/composables/useTheme';

/*
 * The theme was already applied by the inline script in app.blade.php (to avoid
 * a white flash before the bundle loads).  Calling init here keeps the Vue
 * state in step with what the document is already showing.
 */
initTheme();

createApp(AppLayout)
    .use(createPinia())
    .use(router)
    .mount('#app');
