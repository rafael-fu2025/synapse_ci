/** Alpine components shared by direct routes and asynchronously loaded modal forms. */
(function () {
  'use strict';
  function patientSearch(config) {
    return {
      searchTerm: '', results: [], selectedPerson: null, searchController: null,
      async searchPatient() {
        this.searchController?.abort();
        if (this.searchTerm.trim().length < 2) { this.results = []; return; }
        const controller = new AbortController();
        this.searchController = controller;
        const url = new URL(config.patientUrl, location.href);
        url.searchParams.set('q', this.searchTerm);
        const finishLoading = window.SynapseLoading?.begin(this.$root?.querySelector('.input-group')?.parentElement, { label: 'Searching directory…' });
        try {
          const response = await fetch(url, { signal: controller.signal });
          if (controller.signal.aborted || window.SynapseCsrf?.fromResponse(response)) return;
          const payload = response.ok ? await response.json() : null;
          if (!controller.signal.aborted) this.results = payload?.results || [];
        } catch (error) { if (error.name !== 'AbortError' && !controller.signal.aborted) this.results = []; }
        finally { finishLoading?.(); }
      },
      selectPerson(person) { this.selectedPerson = person; this.results = []; },
      destroy() { this.searchController?.abort(); this.slotsController?.abort(); }
    };
  }
  window.referralForm = config => {
    const state = patientSearch(config);
    try {
      const person = JSON.parse(config.initialPerson || 'null');
      if (person && Number.isSafeInteger(Number(person.id)) && Number(person.id) > 0) state.selectedPerson = person;
    } catch (_) { /* Manual search remains available for malformed optional prefill. */ }
    return state;
  };
  window.newCaseForm = config => patientSearch(config);
  window.appointmentRequest = config => ({ ...patientSearch(config), date: config.initialDate });
  window.appointmentBooking = config => ({
    providerId: '', date: config.initialDate,
    slots: [], selectedSlot: null, loadingSlots: false, slotsController: null, slotError: '',
    destroy() { this.slotsController?.abort(); },
    formatSlotTime(time) {
      const match = /^(\d{2}):(\d{2})(?::\d{2})?$/.exec(time);
      if (!match || Number(match[1]) > 23 || Number(match[2]) > 59) return time;
      const hour = Number(match[1]);
      return `${hour % 12 || 12}:${match[2]} ${hour >= 12 ? 'PM' : 'AM'}`;
    },
    async fetchSlots() {
      this.slotsController?.abort();
      this.selectedSlot = null;
      this.slots = [];
      this.slotError = '';
      if (!this.providerId || !this.date) { this.loadingSlots = false; return; }
      const controller = new AbortController();
      this.slotsController = controller;
      this.loadingSlots = true;
      const url = new URL(config.slotsUrl, location.href);
      url.searchParams.set('unit', config.unit);
      url.searchParams.set('staff_assignment_id', this.providerId);
      url.searchParams.set('date', this.date);
      try {
        const response = await fetch(url, { signal: controller.signal });
        if (controller.signal.aborted || window.SynapseCsrf?.fromResponse(response)) return;
        const result = await response.json();
        if (!controller.signal.aborted) {
          if (!response.ok) this.slotError = result.message || 'Unable to check availability. Please try again.';
          else this.slots = result.slots || [];
        }
      } catch (error) {
        if (error.name !== 'AbortError' && !controller.signal.aborted) this.slotError = 'Unable to check availability. Please try again.';
      }
      finally { if (this.slotsController === controller) this.loadingSlots = false; }
    }
  });
})();
