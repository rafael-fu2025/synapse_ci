/** Alpine components shared by direct routes and asynchronously loaded modal forms. */
(function () {
  'use strict';
  function patientSearch(config) {
    return {
      searchTerm: '', results: [], selectedPerson: null, searchController: null,
      searching: false, searchError: '', searchComplete: false,
      resetSearch() {
        this.searchController?.abort();
        this.searchController = null;
        this.results = [];
        this.searching = false;
        this.searchError = '';
        this.searchComplete = false;
      },
      patientInput() { this.resetSearch(); },
      async searchPatient() {
        this.resetSearch();
        const term = this.searchTerm.trim();
        if (term.length < 2 || this.searchTerm.length > 150 || this.selectedPerson) return;
        const controller = new AbortController();
        this.searchController = controller;
        this.searching = true;
        const current = () => this.searchController === controller && !controller.signal.aborted
          && this.searchTerm.trim() === term && !this.selectedPerson;
        const url = new URL(config.patientUrl, location.href);
        url.searchParams.set('q', term);
        try {
          const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
          if (!current() || window.SynapseCsrf?.fromResponse(response)) return;
          if (!response.ok) throw new Error('Lookup failed');
          const payload = await response.json();
          if (!Array.isArray(payload.results)) throw new Error('Invalid lookup response');
          if (current()) {
            this.results = payload.results;
            this.searchComplete = true;
          }
        } catch (error) {
          if (error.name !== 'AbortError' && current()) this.searchError = 'Unable to search the local directory. Please try again.';
        } finally {
          if (this.searchController === controller) {
            this.searchController = null;
            this.searching = false;
          }
        }
      },
      selectPerson(person) { this.resetSearch(); this.selectedPerson = person; },
      changePerson() { this.resetSearch(); this.selectedPerson = null; this.searchTerm = ''; },
      destroy() { this.resetSearch(); this.slotsController?.abort(); }
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
