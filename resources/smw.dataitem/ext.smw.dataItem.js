/**
 * SMW DataItem JavaScript representation
 *
 * @since 1.9
 *
 * @file
 * @ingroup SMW
 *
 * @licence GNU GPL v2 or later
 * @author mwjames
 */
( function( $, mw, smw ) {
	'use strict';

	/**
	 * Constructor
	 *
	 * @var Object
	 */
	smw.dataItem = function() {};

	/**
	 * Public methods
	 *
	 * @since  1.9
	 *
	 * @type object
	 */
	smw.dataItem.prototype = {

		properties: null,

		/**
		 * Factory methods that maps an JSON.parse key/value to an dataItem object
		 * This function is normally only called during smw.Api.parse/fetch()
		 *
		 * Structure will be similar to
		 *
		 * Subject (if exists is of type smw.dataItem.wikiPage otherwise a simple object)
		 * |--> property -> smw.dataItem.property
		 *         |--> smw.dataItem.wikiPage
		 *         |--> ...
		 * |--> property -> smw.dataItem.property
		 *         |--> smw.dataItem.uri
		 *         |--> ...
		 * |--> property -> smw.dataItem.property
		 *         |--> smw.dataItem.time
		 *         |--> ...
		 *
		 * @since  1.9
		 *
		 * @param {string} key
		 * @param {mixed} value
		 *
		 * @return {object}
		 */
		factory: function( key, value ) {
			var self = this;

			// Map printrequests in order to be used as key accessible reference object
			// which enables type hinting for all items that exists within in this list
			if ( key === 'printrequests' && value !== undefined ){
				var list = {};
				$.map( value, function( key, index ) {
					list[key.label] = { typeid: key.typeid, position: index };
				} );
				self.properties = list;
			}

			// Map the entire result object, for objects that have a subject as
			// full fledged head item and rebuild the entire object to ensure
			// that wikiPage is invoked at the top as well
			if ( key === 'results' ){
				var nResults = {};

				$.each( value, function( subjectName, subject ) {
					if( subject.hasOwnProperty( 'fulltext' ) ){
						var nSubject = new smw.dataItem.wikiPage( subject.fulltext, subject.fullurl, subject.namespace );
						nSubject.printouts = subject.printouts;
						nResults[subjectName] = nSubject;
					} else {
						// Headless entry without a subject
						nResults = value;
					}
				} );

				return nResults;
			}

			// Map individual properties according to its type
			if ( typeof value === 'object' && self.properties !== null ){
				if ( key !== '' && value.length > 0 && self.properties.hasOwnProperty( key ) ){
					var property = new smw.dataItem.property( key );
					var factoredValue = [];

					// Assignment of individual classes
					switch ( self.properties[key].typeid ) {
						case '_wpg':
							$.map( value, function( w ) {
								factoredValue.push( new smw.dataItem.wikiPage( w.fulltext, w.fullurl, w.namespace ) );
							} );
							break;
						case '_uri':
							$.map( value, function( u ) {
								factoredValue.push( new smw.dataItem.uri( u ) );
							} );
							break;
						case '_dat':
							$.map( value, function( t ) {
								factoredValue.push( new smw.dataItem.time( t ) );
							} );
							break;
						// Assign remaining values that to not have a smw.dataItem
						// object but belong to smw.dataItem.property
						default: factoredValue = value;
					}

					return $.extend( property, factoredValue );
				}
			}

			// Return all other values
			return value;
		}
	};

} )( jQuery, mediaWiki, semanticMediaWiki );