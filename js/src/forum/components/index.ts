/*
 * The one lazy chunk: the card, the dialogs, the voters list and the Stances page. forum.js
 * only wires things up; everything here loads the first time a page, a block or a dialog needs
 * it. One entry on purpose: two chunks sharing the card would make webpack emit a numbered shared
 * chunk that jsDirectory doesn't publish.
 */
export { mountCard } from './mount';
export { default as StanceCard } from './StanceCard';
export { default as StanceModal } from './StanceModal';
export { default as ComposerModal, openAdd } from './ComposerModal';
export { default as VotersModal } from './VotersModal';
export { default as StancesPage, loadCore } from './StancesPage';
