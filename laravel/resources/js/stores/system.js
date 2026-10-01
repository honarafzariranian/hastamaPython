import { defineStore } from 'pinia';
import { ref } from 'vue';
import api from '@/services/api';

/**
 * Application and database health.
 *
 * This is the migrated equivalent of the existing `/health` and
 * `/health/database` endpoints.  It is shared state on purpose: any future
 * page that needs to know whether the backend and SQL Server are answering
 * reads it from here instead of issuing its own request.
 *
 * These calls deliberately ignore failures into `error` rather than throwing,
 * because a status page that blows up when the backend is down would be
 * useless exactly when it is needed.
 */
export const useSystemStore = defineStore('system', () => {
    const health = ref(null);
    const database = ref(null);
    const loading = ref(false);
    const error = ref(null);
    const checkedAt = ref(null);

    async function fetchHealth() {
        try {
            const response = await api.get('/health');
            health.value = response.data ?? null;
            error.value = null;
        } catch (failure) {
            health.value = null;
            error.value = failure;
        }
    }

    async function fetchDatabase() {
        try {
            const response = await api.get('/health/database');
            database.value = response.data ?? null;
        } catch (failure) {
            database.value = null;
            // Only surface the database failure if nothing more specific has
            // already been recorded, so the first cause is the one shown.
            error.value = error.value ?? failure;
        }
    }

    /**
     * Refresh both probes.  Resolves even when the backend is unreachable.
     */
    async function refresh() {
        loading.value = true;
        error.value = null;

        try {
            await fetchHealth();
            await fetchDatabase();
        } finally {
            checkedAt.value = new Date();
            loading.value = false;
        }
    }

    return {
        health,
        database,
        loading,
        error,
        checkedAt,
        fetchHealth,
        fetchDatabase,
        refresh,
    };
});
