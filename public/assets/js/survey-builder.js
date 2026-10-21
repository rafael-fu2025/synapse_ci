/** Shared survey draft component; bank choices are independent, editable snapshots. */
(function () {
  'use strict';
  window.SynapseSurveyBuilder = function (questions, audience, catalogUrl) {
    return {
      questions, audience, catalogUrl, bankOpen: false, bankBusy: false,
      bankQuery: '', bankCategory: '', bankAudience: '', bankItems: [], bankPage: 1, bankPages: 1,
      bankError: '', bankMessage: '', bankSelected: [], bankSelectionError: '', controller: null,
      bankDebounce: null, bankTimeout: null, bankSequence: 0, bankDestroyed: false,
      bankComposing: { bankQuery: false, bankCategory: false },
      cancelBankSearch() {
        ++this.bankSequence;
        clearTimeout(this.bankDebounce);
        clearTimeout(this.bankTimeout);
        this.bankDebounce = this.bankTimeout = null;
        this.controller?.abort();
        this.controller = null;
        this.bankBusy = false;
      },
      async openBank() {
        if (this.bankDestroyed) return;
        this.bankOpen = !this.bankOpen;
        if (this.bankOpen) await this.searchBank(1);
        else this.cancelBankSearch();
      },
      bankFilterInput(field, event, immediate = false) {
        this[field] = event.target.value;
        this.cancelBankSearch();
        this.bankPage = this.bankPages = 1;
        this.bankItems = [];
        this.bankError = '';
        if (!this.bankOpen || this.bankDestroyed || event.isComposing || Object.values(this.bankComposing).some(Boolean)) return;
        if (immediate) return this.searchBank(1);
        this.bankDebounce = setTimeout(() => {
          this.bankDebounce = null;
          this.searchBank(1);
        }, 300);
      },
      bankComposition(field, event, composing) {
        this.bankComposing[field] = composing;
        this.bankFilterInput(field, event);
      },
      bankSearchEnter(event) {
        if (event.isComposing || event.keyCode === 229 || Object.values(this.bankComposing).some(Boolean)) return;
        event.preventDefault();
        return this.searchBank(1);
      },
      async searchBank(page = 1) {
        this.cancelBankSearch();
        if (!this.bankOpen || this.bankDestroyed || Object.values(this.bankComposing).some(Boolean)) return;
        const sequence = this.bankSequence;
        const controller = new AbortController();
        this.controller = controller;
        const current = () => sequence === this.bankSequence && this.controller === controller
          && !controller.signal.aborted && this.bankOpen && !this.bankDestroyed;
        this.bankBusy = true;
        this.bankError = '';
        this.bankItems = [];
        this.bankTimeout = setTimeout(() => {
          if (!current()) return;
          this.cancelBankSearch();
          this.bankError = 'The question bank request timed out. Please retry.';
        }, 10000);
        try {
          const url = new URL(this.catalogUrl, location.href);
          if (url.origin !== location.origin || url.username || url.password || url.search || url.hash
              || !url.pathname.endsWith('/guidance/question-bank/catalog')
              || /%|\\/.test(url.pathname)) throw new Error('Invalid question bank address. Reload this page.');
          url.search = new URLSearchParams({ q: this.bankQuery, category: this.bankCategory, audience: this.bankAudience, page });
          const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal, redirect: 'error',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
          if (!current()) return;
          if (response.redirected || (response.url && new URL(response.url).href !== url.href)
              || !response.headers.get('Content-Type')?.toLowerCase().includes('application/json')) {
            throw new Error('Unexpected question bank response. Reload this page.');
          }
          const data = await response.json();
          if (!current()) return;
          if (!response.ok || !data || data.success !== true) {
            throw new Error(typeof data?.message === 'string' ? data.message.slice(0, 1500) : 'The question bank could not be loaded.');
          }
          if (!Array.isArray(data.items) || !data.items.every(item => this.validBankItem(item))
              || !Number.isInteger(data.page) || !Number.isInteger(data.pages)
              || data.page < 1 || data.pages < data.page) throw new Error('Invalid question bank data. Please retry.');
          this.bankItems = data.items;
          this.bankPage = data.page;
          this.bankPages = data.pages;
        } catch (error) {
          if (current() && error?.name !== 'AbortError') this.bankError = error?.message || 'The question bank could not be loaded.';
        } finally {
          if (current()) {
            clearTimeout(this.bankTimeout);
            this.bankTimeout = null;
            this.controller = null;
            this.bankBusy = false;
          }
        }
      },
      validBankItem(item) {
        const question = item?.question;
        return item && Number.isInteger(item.id) && item.id > 0 && Number.isInteger(item.revision) && item.revision > 0
          && typeof item.category === 'string' && ['all', 'students', 'employees'].includes(item.audience)
          && (item.review_note == null || typeof item.review_note === 'string')
          && question && typeof question.label === 'string' && typeof question.required === 'boolean'
          && ['text', 'single', 'multiple', 'rating'].includes(question.type)
          && Array.isArray(question.options) && question.options.every(option => typeof option === 'string');
      },
      bankItemKey(item) {
        return item.id + ':' + item.revision;
      },
      isBankSelected(item) {
        return this.bankSelected.some(selected => this.bankItemKey(selected) === this.bankItemKey(item));
      },
      bankPageSelected() {
        return this.bankItems.length > 0 && this.bankItems.every(item => this.isBankSelected(item));
      },
      bankPagePartlySelected() {
        return this.bankItems.some(item => this.isBankSelected(item)) && !this.bankPageSelected();
      },
      copyBankItem(item) {
        return { ...item, question: { ...item.question, options: [...item.question.options] } };
      },
      toggleBankSelection(item, checked) {
        this.bankSelectionError = '';
        if (!checked) {
          this.bankSelected = this.bankSelected.filter(selected => this.bankItemKey(selected) !== this.bankItemKey(item));
        } else if (!this.isBankSelected(item)) {
          if (this.bankSelected.length >= 20) { this.bankSelectionError = 'Select at most 20 bank questions at a time.'; return; }
          this.bankSelected.push(this.copyBankItem(item));
        }
      },
      toggleBankPage(checked) {
        this.bankSelectionError = '';
        if (!checked) {
          const visible = new Set(this.bankItems.map(item => this.bankItemKey(item)));
          this.bankSelected = this.bankSelected.filter(item => !visible.has(this.bankItemKey(item)));
          return;
        }
        const additional = this.bankItems.filter(item => !this.isBankSelected(item));
        if (this.bankSelected.length + additional.length > 20) {
          this.bankSelectionError = 'Selecting this page would exceed 20 selections. Clear some selections or choose individual questions.';
          return;
        }
        this.bankSelected.push(...additional.map(item => this.copyBankItem(item)));
      },
      clearBankSelection() {
        this.bankSelected = [];
        this.bankSelectionError = '';
      },
      blankInitialQuestion() {
        return this.questions.length === 1 && !this.questions[0].label.trim() && !this.questions[0].bank_id;
      },
      remainingQuestionSlots() {
        return 20 - this.questions.length + (this.blankInitialQuestion() ? 1 : 0);
      },
      addBankQuestions(items) {
        if (items.length === 0) return false;
        const remaining = this.remainingQuestionSlots();
        if (items.length > remaining) {
          this.bankSelectionError = 'Only ' + remaining + ' question slot(s) remain. Deselect some questions or remove existing questions; nothing was added.';
          return false;
        }
        const copies = items.map(item => ({ ...item.question, options: [...item.question.options], bank_id: item.id, bank_revision: item.revision }));
        if (this.blankInitialQuestion()) this.questions.splice(0, 1, ...copies);
        else this.questions.push(...copies);
        this.bankSelectionError = '';
        this.bankMessage = 'Added ' + copies.length + ' bank question ' + (copies.length === 1 ? 'copy' : 'copies') + '. Review their intended audiences before saving.';
        return true;
      },
      addBankQuestion(item) {
        if (this.addBankQuestions([item])) {
          this.bankSelected = this.bankSelected.filter(selected => this.bankItemKey(selected) !== this.bankItemKey(item));
        }
      },
      addSelectedBankQuestions() {
        if (this.addBankQuestions(this.bankSelected)) this.clearBankSelection();
      },
      detach(question) {
        delete question.bank_id;
        delete question.bank_revision;
      },
      destroy() {
        this.bankDestroyed = true;
        this.bankOpen = false;
        this.cancelBankSearch();
      }
    };
  };
})();
