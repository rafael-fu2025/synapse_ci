/** Shared survey draft component; bank choices are independent, editable snapshots. */
(function () {
  'use strict';
  window.SynapseSurveyBuilder = function (questions, audience, catalogUrl, surveyMode = 'internal', externalUrl = '', aiPrefillUrl = '') {
    return {
      questions, audience, catalogUrl,
      surveyMode: surveyMode === 'external' ? 'external' : 'internal',
      externalUrl: typeof externalUrl === 'string' ? externalUrl : '',
      aiPrefillUrl: typeof aiPrefillUrl === 'string' ? aiPrefillUrl : '',
      aiOpen: false, aiBusy: false, aiError: '', aiMessage: '', aiWarnings: [],
      aiText: '', aiFileName: '', aiMergeMode: 'replace', aiFillMeta: true,
      aiPercent: 0, aiStep: 0, aiTotalSteps: 4, aiStage: '', aiStageLabel: '',
      aiElapsedSec: 0, aiElapsedTimer: null,
      aiShowRawSse: false, aiRawLog: [], aiClarifications: [],
      aiController: null, aiTimeout: null, aiSequence: 0,
      bankOpen: false, bankBusy: false,
      bankQuery: '', bankCategory: '', bankAudience: '', bankItems: [], bankPage: 1, bankPages: 1,
      bankError: '', bankMessage: '', bankSelected: [], bankSelectionError: '', controller: null,
      bankDebounce: null, bankTimeout: null, bankSequence: 0, bankDestroyed: false,
      bankComposing: { bankQuery: false, bankCategory: false },
      setMode(mode) {
        this.surveyMode = mode === 'external' ? 'external' : 'internal';
        if (this.surveyMode !== 'internal') {
          this.bankOpen = false;
          this.cancelBankSearch();
          this.aiOpen = false;
          this.cancelAiPrefill();
        }
      },
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
          const response = await fetch(url, {
            credentials: 'same-origin', signal: controller.signal, redirect: 'error',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store'
          });
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
      toggleAi() {
        if (this.bankDestroyed) return;
        this.aiOpen = !this.aiOpen;
        if (!this.aiOpen) this.cancelAiPrefill();
      },
      cancelAiPrefill(keepProgress = false) {
        ++this.aiSequence;
        clearTimeout(this.aiTimeout);
        clearTimeout(this.aiElapsedTimer);
        this.aiTimeout = null;
        this.aiElapsedTimer = null;
        this.aiController?.abort();
        this.aiController = null;
        this.aiBusy = false;
        if (!keepProgress) {
          this.aiPercent = 0;
          this.aiStep = 0;
          this.aiStage = '';
          this.aiStageLabel = '';
        }
      },
      onAiFileChange(event) {
        const file = event?.target?.files?.[0] || null;
        this.aiFileName = file ? file.name : '';
        this.aiError = '';
      },
      clearAiFile(fileInputEl) {
        if (fileInputEl) fileInputEl.value = '';
        this.aiFileName = '';
      },
      appendAiRawLine(line) {
        if (typeof line !== 'string' || !line) return;
        this.aiRawLog.push(line);
        if (this.aiRawLog.length > 200) {
          this.aiRawLog.splice(0, this.aiRawLog.length - 200);
        }
      },
      pickClarificationOption(item, option) {
        if (!item || typeof option !== 'string') return;
        item.answer = option;
      },
      normalizeAiQuestion(item) {
        if (!item || typeof item !== 'object') return null;
        const type = ['text', 'single', 'multiple', 'rating'].includes(item.type) ? item.type : 'text';
        const label = typeof item.label === 'string' ? item.label.trim().slice(0, 500) : '';
        if (!label) return null;
        const required = item.required !== false;
        let options = [];
        if (['single', 'multiple'].includes(type)) {
          options = Array.isArray(item.options)
            ? item.options.map(opt => (typeof opt === 'string' ? opt.trim().slice(0, 200) : '')).filter(Boolean).slice(0, 10)
            : [];
          if (options.length < 2) {
            return { type: 'text', label, required, options: [], scale_min_label: '', scale_max_label: '' };
          }
        }
        const minLabel = type === 'rating' && typeof item.scale_min_label === 'string'
          ? item.scale_min_label.trim().slice(0, 100)
          : '';
        const maxLabel = type === 'rating' && typeof item.scale_max_label === 'string'
          ? item.scale_max_label.trim().slice(0, 100)
          : '';
        return {
          type,
          label,
          required,
          options,
          allow_other: ['single', 'multiple'].includes(type) && Boolean(item.allow_other),
          scale_min_label: minLabel,
          scale_max_label: maxLabel,
        };
      },
      applyAiResult(data, titleEl, descEl) {
        const normalized = Array.isArray(data?.questions)
          ? data.questions.map(q => this.normalizeAiQuestion(q)).filter(Boolean).slice(0, 20)
          : [];
        if (normalized.length === 0) {
          throw new Error('AI response contained no usable survey questions.');
        }

        const warnings = Array.isArray(data?.warnings)
          ? data.warnings.filter(w => typeof w === 'string' && w.trim() !== '')
          : [];
        let appliedCount = 0;

        if (this.aiMergeMode === 'append' && !this.blankInitialQuestion()) {
          const slots = this.remainingQuestionSlots();
          if (slots <= 0) {
            this.aiError = 'Survey already has 20 questions. Remove existing questions or switch to Replace mode.';
            return false;
          }
          const slice = normalized.slice(0, slots);
          if (slice.length < normalized.length) {
            warnings.push('Added ' + slice.length + ' of ' + normalized.length + ' extracted questions due to the 20-question limit.');
          }
          this.questions.push(...slice);
          appliedCount = slice.length;
        } else {
          this.questions.splice(0, this.questions.length, ...normalized);
          appliedCount = normalized.length;
        }

        if (this.aiFillMeta) {
          if (titleEl && !titleEl.value.trim() && typeof data?.suggested_title === 'string' && data.suggested_title.trim()) {
            titleEl.value = data.suggested_title.trim().slice(0, 200);
          }
          if (descEl && !descEl.value.trim() && typeof data?.suggested_description === 'string' && data.suggested_description.trim()) {
            descEl.value = data.suggested_description.trim().slice(0, 2000);
          }
        }

        this.aiClarifications = Array.isArray(data?.clarifications)
          ? data.clarifications
            .filter(c => c && typeof c.question === 'string' && c.question.trim() !== '')
            .slice(0, 5)
            .map((c, idx) => ({
              id: typeof c.id === 'string' && c.id ? c.id : 'c' + (idx + 1),
              question: c.question.trim().slice(0, 300),
              suggested_options: Array.isArray(c.suggested_options)
                ? c.suggested_options.filter(o => typeof o === 'string' && o.trim() !== '').slice(0, 4)
                : [],
              answer: '',
            }))
          : [];

        this.aiWarnings = warnings;
        this.aiError = '';
        this.aiPercent = 100;
        this.aiStep = 4;
        this.aiStage = 'done';
        this.aiStageLabel = 'Questions ready for review.';
        this.aiMessage = 'Prefilled ' + appliedCount + ' question' + (appliedCount === 1 ? '' : 's') + '. Review all questions and options before saving your draft.';
        return true;
      },
      async readAiSseStream(response, current, csrf) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';
        let finalResult = null;

        const dispatchBlock = block => {
          const lines = block.split(/\r?\n/);
          let eventName = 'message';
          const dataLines = [];
          for (const line of lines) {
            if (line.startsWith('event:')) eventName = line.slice(6).trim();
            else if (line.startsWith('data:')) dataLines.push(line.slice(5).trim());
          }
          if (dataLines.length === 0) return;
          const rawJson = dataLines.join('\n');
          let parsed;
          try {
            parsed = JSON.parse(rawJson);
          } catch (_) {
            return;
          }
          if (eventName === 'stage' && parsed) {
            if (typeof parsed.percent === 'number') this.aiPercent = Math.max(0, Math.min(100, parsed.percent));
            if (typeof parsed.step === 'number') this.aiStep = parsed.step;
            if (typeof parsed.total === 'number') this.aiTotalSteps = parsed.total;
            if (typeof parsed.stage === 'string') this.aiStage = parsed.stage;
            if (typeof parsed.label === 'string') this.aiStageLabel = parsed.label;
          } else if (eventName === 'raw' && typeof parsed?.line === 'string') {
            this.appendAiRawLine(parsed.line);
          } else if (eventName === 'failure') {
            csrf?.sync(parsed?.csrf);
            throw new Error(typeof parsed?.message === 'string' ? parsed.message.slice(0, 1500) : 'AI prefill could not extract questions.');
          } else if (eventName === 'result') {
            csrf?.sync(parsed?.csrf);
            finalResult = parsed;
          }
        };

        while (true) {
          const { value, done } = await reader.read();
          if (!current()) return null;
          if (value) {
            buffer += decoder.decode(value, { stream: !done });
            let sepIdx;
            while ((sepIdx = buffer.search(/\r?\n\r?\n/)) !== -1) {
              const block = buffer.slice(0, sepIdx);
              const match = buffer.slice(sepIdx).match(/^\r?\n\r?\n/);
              buffer = buffer.slice(sepIdx + (match ? match[0].length : 2));
              if (block.trim()) dispatchBlock(block);
            }
          }
          if (done) break;
        }
        if (buffer.trim()) dispatchBlock(buffer);
        if (!finalResult || finalResult.success !== true) {
          throw new Error('AI stream ended before a complete question set was received.');
        }
        return finalResult;
      },
      async runAiPrefill(fileInputEl, titleEl, descEl, withClarifications = false) {
        if (this.bankDestroyed) return;
        const file = fileInputEl?.files?.[0] || null;
        const rawText = typeof this.aiText === 'string' ? this.aiText.trim() : '';
        this.aiError = '';
        this.aiMessage = '';
        this.aiWarnings = [];

        if (!file && !rawText) {
          this.aiError = 'Paste survey questions or choose a .txt, .md, .csv, .docx, or .pdf file first.';
          return;
        }
        if (file && file.size > 2097152) {
          this.aiError = 'Selected file exceeds the 2 MB upload limit.';
          return;
        }

        const clarificationsPayload = withClarifications
          ? this.aiClarifications
            .map(c => ({ question: String(c?.question || '').trim(), answer: String(c?.answer || '').trim() }))
            .filter(c => c.question && c.answer)
          : [];
        if (withClarifications && clarificationsPayload.length === 0) {
          this.aiError = 'Answer at least one clarification question before re-extracting.';
          return;
        }

        this.cancelAiPrefill();
        const sequence = this.aiSequence;
        const controller = new AbortController();
        this.aiController = controller;
        const current = () => sequence === this.aiSequence && this.aiController === controller
          && !controller.signal.aborted && !this.bankDestroyed;

        this.aiBusy = true;
        this.aiRawLog = [];
        this.aiPercent = 5;
        this.aiStep = 1;
        this.aiTotalSteps = 4;
        this.aiStage = 'starting';
        this.aiStageLabel = 'Sending survey source for extraction...';
        this.aiElapsedSec = 0;
        if (!withClarifications) {
          this.aiClarifications = [];
        }
        const scheduleElapsed = () => {
          this.aiElapsedTimer = setTimeout(() => {
            if (!current()) return;
            ++this.aiElapsedSec;
            scheduleElapsed();
          }, 1000);
        };
        scheduleElapsed();
        this.aiTimeout = setTimeout(() => {
          if (!current()) return;
          this.cancelAiPrefill();
          this.aiError = 'AI extraction timed out. Try shortening the input or adding questions manually.';
        }, 60000);

        try {
          const url = new URL(this.aiPrefillUrl, location.href);
          if (url.origin !== location.origin || url.username || url.password || url.search || url.hash
            || !url.pathname.endsWith('/guidance/surveys/ai-prefill')
            || /%|\\/.test(url.pathname)) {
            throw new Error('Invalid AI prefill endpoint address. Reload this page.');
          }

          const body = new FormData();
          if (file) body.append('survey_file', file);
          if (rawText) body.append('raw_text', rawText);
          if (clarificationsPayload.length > 0) {
            body.append('clarifications_json', JSON.stringify(clarificationsPayload));
          }

          const csrf = typeof window !== 'undefined' ? window.SynapseCsrf : null;
          const response = await fetch(url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            signal: controller.signal,
            redirect: 'error',
            headers: {
              Accept: 'text/event-stream, application/json',
              'X-Requested-With': 'XMLHttpRequest',
              ...(csrf ? csrf.headers() : {}),
            },
            cache: 'no-store',
          });
          if (!current()) return;
          if (csrf?.fromResponse(response)) {
            throw new Error('Session authentication changed. Sign in again to continue.');
          }

          const contentType = response.headers.get('Content-Type')?.toLowerCase() || '';
          let data;
          if (contentType.includes('text/event-stream') && response.body && typeof response.body.getReader === 'function') {
            data = await this.readAiSseStream(response, current, csrf);
            if (!data || !current()) return;
          } else {
            if (!contentType.includes('application/json')) {
              throw new Error('Unexpected response from AI prefill endpoint.');
            }
            data = await response.json();
            if (!current()) return;
            csrf?.sync(data?.csrf);
            if (!response.ok || !data || data.success !== true) {
              throw new Error(typeof data?.message === 'string' ? data.message.slice(0, 1500) : 'AI prefill could not extract questions.');
            }
          }

          this.applyAiResult(data, titleEl, descEl);
        } catch (error) {
          if (current() && error?.name !== 'AbortError') {
            this.aiPercent = 0;
            this.aiError = error?.message || 'AI prefill failed. Please review your input or add questions manually.';
          }
        } finally {
          if (current()) {
            clearTimeout(this.aiTimeout);
            clearTimeout(this.aiElapsedTimer);
            this.aiTimeout = null;
            this.aiElapsedTimer = null;
            this.aiController = null;
            this.aiBusy = false;
          }
        }
      },
      destroy() {
        this.bankDestroyed = true;
        this.bankOpen = false;
        this.aiOpen = false;
        this.cancelBankSearch();
        this.cancelAiPrefill();
      }
    };
  };
})();
