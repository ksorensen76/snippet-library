/**
 * Gravity Perks // Page Transitions // Scroll to Top for Long Pages
 * https://gravitywiz.com/documentation/gravity-forms-page-transitions/
 */
gform.addAction( 'gppt_before_transition', function() {
  window.scroll( {
    top: 0,
    left: 0,
    behavior: 'smooth'
  } );
} );
