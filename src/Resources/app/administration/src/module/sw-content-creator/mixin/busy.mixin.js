/**
 * Gemeinsames Busy-/Fehler-Muster für Scan- und Aktions-Buttons:
 * Busy-Flag setzen, Arbeit ausführen, Fehler als Notification, Flag zurücksetzen.
 */
export default {
    methods: {
        notifyApiError(err) {
            this.createNotificationError({ message: err?.response?.data?.error || err.message });
        },

        runBusy(busyProp, work) {
            this[busyProp] = true;

            return Promise.resolve()
                .then(work)
                .catch((err) => this.notifyApiError(err))
                .finally(() => { this[busyProp] = false; });
        },
    },
};
