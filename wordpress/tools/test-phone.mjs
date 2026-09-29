import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../scripts/app.js', import.meta.url), 'utf8');
const context = vm.createContext({document: {getElementById: () => null, addEventListener() {}}, console});
vm.runInContext(source.replace(/\}\)\(\);\s*$/, 'globalThis.phoneTest = {phoneNationalDigits, formatRussianPhone, configurePhoneInput, createRequestId};})();'), context);
const {phoneNationalDigits, formatRussianPhone, configurePhoneInput, createRequestId} = context.phoneTest;

class PhoneInput {
  value = '';
  dataset = {};
  listeners = {};
  selectionStart = 0;
  selectionEnd = 0;
  error = '';
  form = {addEventListener: (type, handler) => { this.formReset = handler; }};
  addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); }
  setCustomValidity(message) { this.error = message; }
  setSelectionRange(start, end) { this.selectionStart = Math.min(start, this.value.length); this.selectionEnd = Math.min(end, this.value.length); }
  emit(type, extra = {}) { const event = {preventDefault() { this.prevented = true; }, ...extra}; this.listeners[type]?.forEach((handler) => handler(event)); return event; }
}

test('Russian number accepts +7, domestic 8, and ten national digits', () => {
  for (const source of ['+7 (999) 123-45-67', '89991234567', '9991234567']) {
    assert.equal(formatRussianPhone(source), '+7 (999) 123-45-67');
    assert.equal(phoneNationalDigits(source), '9991234567');
  }
  assert.equal(formatRussianPhone(''), '');
  assert.equal(formatRussianPhone('+7'), '+7');
  assert.equal(formatRussianPhone('+799912345678'), '+7 (999) 123-45-678', 'extra digits must remain visible and invalid, never silently truncated');
});

test('focus supplies +7, partial input stays invalid, full number becomes valid', () => {
  const input = new PhoneInput();
  configurePhoneInput(input);
  input.emit('focus');
  assert.equal(input.value, '+7');
  assert.equal(input.selectionStart, 2);
  assert.notEqual(input.error, '');
  input.value += '9991234567';
  input.setSelectionRange(input.value.length, input.value.length);
  input.emit('input');
  assert.equal(input.value, '+7 (999) 123-45-67');
  assert.equal(input.error, '');
  assert.equal(input.selectionStart, input.value.length);
});

test('pasting a complete number replaces the supplied prefix without duplicating it', () => {
  const input = new PhoneInput();
  configurePhoneInput(input);
  input.emit('focus');
  const event = input.emit('paste', {clipboardData: {getData: () => '8 (999) 123-45-67'}});
  assert.equal(event.prevented, true);
  assert.equal(input.value, '+7 (999) 123-45-67');
  assert.equal(input.error, '');
});

test('typing a full domestic number after the automatic +7 drops only the extra trunk prefix', () => {
  for (const source of ['89991234567', '79991234567']) {
    const input = new PhoneInput();
    configurePhoneInput(input);
    input.emit('focus');
    for (const digit of source) {
      input.value += digit;
      input.setSelectionRange(input.value.length, input.value.length);
      input.emit('input');
    }
    assert.equal(input.value, '+7 (999) 123-45-67');
    assert.equal(input.error, '');
  }
  assert.equal(formatRussianPhone('+799912345678'), '+7 (999) 123-45-678', 'an unrelated extra digit remains invalid');
});

test('backspace across mask punctuation removes a digit and clearing remains possible', () => {
  const input = new PhoneInput();
  input.value = '+7 (999) 123-45-67';
  configurePhoneInput(input);
  input.setSelectionRange(8, 8);
  const event = input.emit('beforeinput', {inputType: 'deleteContentBackward'});
  assert.equal(event.prevented, true);
  assert.equal(input.value, '+7 (991) 234-56-7');
  assert.notEqual(input.error, '');
  input.value = '';
  input.setSelectionRange(0, 0);
  input.emit('input');
  assert.equal(input.value, '');
  assert.equal(input.error, '');
  input.emit('focus');
  input.emit('beforeinput', {inputType: 'deleteContentBackward'});
  assert.equal(input.value, '');
});

test('reconfiguration does not duplicate listeners and resetting clears custom validity', () => {
  const input = new PhoneInput();
  configurePhoneInput(input);
  configurePhoneInput(input);
  assert.equal(input.listeners.input.length, 1);
  input.emit('focus');
  assert.notEqual(input.error, '');
  input.formReset();
  assert.equal(input.error, '');
  assert.match(createRequestId(), /^[\da-f]{8}-[\da-f]{4}-4[\da-f]{3}-[89ab][\da-f]{3}-[\da-f]{12}$/);
});
