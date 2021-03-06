<?php

use SMW\Query\Language\ClassDescription;
use SMW\Query\Language\ConceptDescription;
use SMW\Query\Language\Conjunction;
use SMW\Query\Language\Description;
use SMW\Query\Language\Disjunction;
use SMW\Query\Language\Negation;
use SMW\Query\Language\NamespaceDescription;
use SMW\Query\Language\SomeProperty;
use SMW\Query\Language\ThingDescription;
use SMW\Query\Language\ValueDescription;

/**
 * Objects of this class are in charge of parsing a query string in order
 * to create an SMWDescription. The class and methods are not static in order
 * to more cleanly store the intermediate state and progress of the parser.
 * @ingroup SMWQuery
 * @author Markus Krötzsch
 */
class SMWQueryParser {

	private $separatorStack; // list of open blocks ("parentheses") that need closing at current step
	private $currentString; // remaining string to be parsed (parsing eats query string from the front)
	private $errorMessages; // empty array if all went right, array of strings otherwise
	private $defaultNamespace; // description of the default namespace restriction, or NULL if not used

	private $categoryPrefix; // cache label of category namespace . ':'
	private $conceptPrefix; // cache label of concept namespace . ':'
	private $categoryPrefixCannonical; // cache canonnical label of category namespace . ':'
	private $conceptPrefixCannonical; // cache canonnical label of concept namespace . ':'
	private $queryFeatures; // query features to be supported, format similar to $smwgQFeatures

	public function __construct( $queryFeatures = false ) {
		global $wgContLang, $smwgQFeatures;

		$this->categoryPrefix = $wgContLang->getNsText( NS_CATEGORY ) . ':';
		$this->conceptPrefix = $wgContLang->getNsText( SMW_NS_CONCEPT ) . ':';
		$this->categoryPrefixCannonical = 'Category:';
		$this->conceptPrefixCannonical = 'Concept:';

		$this->defaultNamespace = null;
		$this->queryFeatures = $queryFeatures === false ? $smwgQFeatures : $queryFeatures;
	}

	/**
	 * Provide an array of namespace constants that are used as default restrictions.
	 * If NULL is given, no such default restrictions will be added (faster).
	 */
	public function setDefaultNamespaces( $namespaceArray ) {
		$this->defaultNamespace = null;

		if ( !is_null( $namespaceArray ) ) {
			foreach ( $namespaceArray as $ns ) {
				$this->defaultNamespace = $this->addDescription(
					$this->defaultNamespace,
					new NamespaceDescription( $ns ),
					SMW_DISJUNCTION_QUERY
				);
			}
		}
	}

	/**
	 * Compute an SMWDescription from a query string. Returns whatever descriptions could be
	 * wrestled from the given string (the most general result being SMWThingDescription if
	 * no meaningful condition was extracted).
	 *
	 * @param string $queryString
	 *
	 * @return Description
	 */
	public function getQueryDescription( $queryString ) {

		$this->errorMessages = array();
		$this->currentString = $queryString;
		$this->separatorStack = array();
		$setNS = false;
		$result = $this->getSubqueryDescription( $setNS );

		if ( !$setNS ) { // add default namespaces if applicable
			$result = $this->addDescription( $this->defaultNamespace, $result );
		}

		if ( is_null( $result ) ) { // parsing went wrong, no default namespaces
			$result = new ThingDescription();
		}


		return $result;
	}

	/**
	 * Return array of error messages (possibly empty).
	 *
	 * @return array
	 */
	public function getErrors() {
		return $this->errorMessages;
	}

	/**
	 * Return error message or empty string if no error occurred.
	 *
	 * @return string
	 */
	public function getErrorString() {
		return smwfEncodeMessages( $this->errorMessages );
	}

	/**
	 * Compute an SMWDescription for current part of a query, which should
	 * be a standalone query (the main query or a subquery enclosed within
	 * "\<q\>...\</q\>". Recursively calls similar methods and returns NULL upon error.
	 *
	 * The call-by-ref parameter $setNS is a boolean. Its input specifies whether
	 * the query should set the current default namespace if no namespace restrictions
	 * were given. If false, the calling super-query is happy to set the required
	 * NS-restrictions by itself if needed. Otherwise the subquery has to impose the defaults.
	 * This is so, since outermost queries and subqueries of disjunctions will have to set
	 * their own default restrictions.
	 *
	 * The return value of $setNS specifies whether or not the subquery has a namespace
	 * specification in place. This might happen automatically if the query string imposes
	 * such restrictions. The return value is important for those callers that otherwise
	 * set up their own restrictions.
	 *
	 * Note that $setNS is no means to switch on or off default namespaces in general,
	 * but just controls query generation. For general effect, the default namespaces
	 * should be set to NULL.
	 *
	 * @return Description|null
	 */
	private function getSubqueryDescription( &$setNS ) {
		$conjunction = null;      // used for the current inner conjunction
		$disjuncts = array();     // (disjunctive) array of subquery conjunctions
		$hasNamespaces = false;   // does the current $conjnuction have its own namespace restrictions?
		$mustSetNS = $setNS;      // must NS restrictions be set? (may become true even if $setNS is false)

		$continue = ( $chunk = $this->readChunk() ) !== ''; // skip empty subquery completely, thorwing an error

		while ( $continue ) {
			$setsubNS = false;

			switch ( $chunk ) {
				case '!':
					$count = 0;
					while ( $chunk == '!' ) {
						$count ++;
						$chunk = $this->readChunk();
					}
					if ( $chunk == '<q>' ) {
						$this->pushDelimiter( '</q>' );
						$ld = $this->getSubqueryDescription( $setsubNS );
						if ( $ld instanceof Disjunction ) {
							foreach ( $ld->getDescriptions() as $desc ) {
								$conjunction = $this->addDescription( $conjunction, $desc, SMW_NEGATION_QUERY );
							}
						} else {
							$conjunction = $this->addDescription( $conjunction, $ld, SMW_NEGATION_QUERY );
						}
					} else {
						$ld = $this->getLinkDescription( $setsubNS );
						if ( !is_null( $ld ) ) {
							$conjunction = $this->addDescription( $conjunction, $ld, $count % 2 == 0 ? SMW_CONJUNCTION_QUERY : SMW_NEGATION_QUERY );
						}
					}
				break;
				case '[[': // start new link block
					$ld = $this->getLinkDescription( $setsubNS );

					if ( !is_null( $ld ) ) {
						$conjunction = $this->addDescription( $conjunction, $ld );
					}
				break;
				case 'AND':
				case '<q>': // enter new subquery, currently irrelevant but possible
					$this->pushDelimiter( '</q>' );
					$conjunction = $this->addDescription( $conjunction, $this->getSubqueryDescription( $setsubNS ) );
				break;
				case 'OR':
				case '||':
				case '':
				case '</q>': // finish disjunction and maybe subquery
					if ( !is_null( $this->defaultNamespace ) ) { // possibly add namespace restrictions
						if ( $hasNamespaces && !$mustSetNS ) {
							// add NS restrictions to all earlier conjunctions (all of which did not have them yet)
							$mustSetNS = true; // enforce NS restrictions from now on
							$newdisjuncts = array();

							foreach ( $disjuncts as $conj ) {
								$newdisjuncts[] = $this->addDescription( $conj, $this->defaultNamespace );
							}

							$disjuncts = $newdisjuncts;
						} elseif ( !$hasNamespaces && $mustSetNS ) {
							// add ns restriction to current result
							$conjunction = $this->addDescription( $conjunction, $this->defaultNamespace );
						}
					}

					$disjuncts[] = $conjunction;
					// start anew
					$conjunction = null;
					$hasNamespaces = false;

					// finish subquery?
					if ( $chunk == '</q>' ) {
						if ( $this->popDelimiter( '</q>' ) ) {
							$continue = false; // leave the loop
						} else {
							$this->errorMessages[] = wfMessage(
								'smw_toomanyclosing',
								$chunk
							)->inContentLanguage()->text();
							return null;
						}
					} elseif ( $chunk === '' ) {
						$continue = false;
					}
				break;
				case '+': // "... AND true" (ignore)
				break;
				default: // error: unexpected $chunk
					$this->errorMessages[] = wfMessage(
						'smw_unexpectedpart',
						$chunk
					)->inContentLanguage()->text();
					// return null; // Try to go on, it can only get better ...
			}

			if ( $setsubNS ) { // namespace restrictions encountered in current conjunct
				$hasNamespaces = true;
			}

			if ( $continue ) { // read on only if $continue remained true
				$chunk = $this->readChunk();
			}
		}

		if ( count( $disjuncts ) > 0 ) { // make disjunctive result
			$result = null;

			foreach ( $disjuncts as $d ) {
				if ( is_null( $d ) ) {
					$this->errorMessages[] = wfMessage( 'smw_emptysubquery' )->inContentLanguage()->text();
					$setNS = false;
					return null;
				} else {
					$result = $this->addDescription( $result, $d, SMW_DISJUNCTION_QUERY );
				}
			}
		} else {
			$this->errorMessages[] = wfMessage( 'smw_emptysubquery' )->inContentLanguage()->text();
			$setNS = false;
			return null;
		}

		$setNS = $mustSetNS; // NOTE: also false if namespaces were given but no default NS descs are available

		return $result;
	}

	/**
	 * Compute an SMWDescription for current part of a query, which should
	 * be the content of "[[ ... ]]". Returns NULL upon error.
	 *
	 * Parameters $setNS has the same use as in getSubqueryDescription().
	 */
	private function getLinkDescription( &$setNS ) {
		// This method is called when we encountered an opening '[['. The following
		// block could be a Category-statement, fixed object, or property statement.
		$chunk = $this->readChunk( '', true, false ); // NOTE: untrimmed, initial " " escapes prop. chains

		if ( in_array( smwfNormalTitleText( $chunk ),
			array( $this->categoryPrefix, $this->conceptPrefix, $this->categoryPrefixCannonical, $this->conceptPrefixCannonical ) ) ) {
			return $this->getClassDescription( $setNS, (
				smwfNormalTitleText( $chunk ) == $this->categoryPrefix || smwfNormalTitleText( $chunk ) == $this->categoryPrefixCannonical
			) );
		} else { // fixed subject, namespace restriction, property query, or subquery
			$sep = $this->readChunk( '', false ); // do not consume hit, "look ahead"

			if ( ( $sep == '::' ) || ( $sep == ':=' ) ) {
				if ( $chunk{0} != ':' ) { // property statement
					return $this->getPropertyDescription( $chunk, $setNS );
				} else { // escaped article description, read part after :: to get full contents
					$chunk .= $this->readChunk( '\[\[|\]\]|\|\||\|' );
					return $this->getArticleDescription( trim( $chunk ), $setNS );
				}
			} else { // Fixed article/namespace restriction. $sep should be ]] or ||
				return $this->getArticleDescription( trim( $chunk ), $setNS );
			}
		}
	}

	/**
	 * Parse a category description (the part of an inline query that
	 * is in between "[[Category:" and the closing "]]" and create a
	 * suitable description.
	 */
	private function getClassDescription( &$setNS, $category = true ) {
		// note: no subqueries allowed here, inline disjunction allowed, wildcards allowed
		$result = null;
		$continue = true;

		while ( $continue ) {
			$chunk = $this->readChunk();

			if ( $chunk == '+' ) {
				// wildcard, ignore for categories (semantically meaningless, everything is in some class)
			} else { // assume category/concept title
				/// NOTE: we add m_c...prefix to prevent problems with, e.g., [[Category:Template:Test]]
				$title = Title::newFromText( ( $category ? $this->categoryPrefix : $this->conceptPrefix ) . $chunk );

				if ( !is_null( $title ) ) {
					$diWikiPage = new SMWDIWikiPage( $title->getDBkey(), $title->getNameSpace(), '' );
					$desc = $category ? new ClassDescription( $diWikiPage ) : new ConceptDescription( $diWikiPage );
					$result = $this->addDescription( $result, $desc, SMW_DISJUNCTION_QUERY );
				}
			}

			$chunk = $this->readChunk();
			$continue = ( $chunk == '||' ) && $category; // disjunctions only for cateories
		}

		return $this->finishLinkDescription( $chunk, false, $result, $setNS );
	}

	/**
	 * Parse a property description (the part of an inline query that
	 * is in between "[[Some property::" and the closing "]]" and create a
	 * suitable description. The "::" is the first chunk on the current
	 * string.
	 */
	private function getPropertyDescription( $propertyName, &$setNS ) {
		$this->readChunk(); // consume separator ":=" or "::"

		// first process property chain syntax (e.g. "property1.property2::value"), escaped by initial " ":
		$propertynames = ( $propertyName{0} == ' ' ) ? array( $propertyName ) : explode( '.', $propertyName );
		$properties = array();
		$typeid = '_wpg';
		$inverse = false;

		foreach ( $propertynames as $name ) {
			if ( !$this->isPagePropertyType( $typeid ) ) { // non-final property in chain was no wikipage: not allowed
				$this->errorMessages[] = wfMessage(
					'smw_valuesubquery',
					$name
				)->inContentLanguage()->text();
				return null; ///TODO: read some more chunks and try to finish [[ ]]
			}

			$property = SMWPropertyValue::makeUserProperty( $name );

			if ( !$property->isValid() ) { // illegal property identifier
				$this->errorMessages = array_merge( $this->errorMessages, $property->getErrors() );
				return null; ///TODO: read some more chunks and try to finish [[ ]]
			}

			$typeid = $property->getDataItem()->findPropertyTypeID();
			$inverse = $property->isInverse();
			$properties[] = $property;
		} ///NOTE: after iteration, $property and $typeid correspond to last value

		$innerdesc = null;
		$continue = true;

		while ( $continue ) {
			$chunk = $this->readChunk();

			switch ( $chunk ) {
				case '+': // wildcard, add namespaces for page-type properties
					if ( !is_null( $this->defaultNamespace ) && ( $this->isPagePropertyType( $typeid ) || $inverse ) ) {
						$innerdesc = $this->addDescription( $innerdesc, $this->defaultNamespace, SMW_DISJUNCTION_QUERY );
					} else {
						$innerdesc = $this->addDescription( $innerdesc, new ThingDescription(), SMW_DISJUNCTION_QUERY );
					}
					$chunk = $this->readChunk();
				break;
				case '<q>': // subquery, set default namespaces
					if ( $this->isPagePropertyType( $typeid ) || $inverse ) {
						$this->pushDelimiter( '</q>' );
						$setsubNS = true;
						$innerdesc = $this->addDescription( $innerdesc, $this->getSubqueryDescription( $setsubNS ), SMW_DISJUNCTION_QUERY );
					} else { // no subqueries allowed for non-pages
						$this->errorMessages[] = wfMessage(
							'smw_valuesubquery',
							end( $propertynames )
						)->inContentLanguage()->text();
						$innerdesc = $this->addDescription( $innerdesc, new ThingDescription(), SMW_DISJUNCTION_QUERY );
					}
					$chunk = $this->readChunk();
				break;
				case '!': // negation of a subquery
					$nextChunk = $this->readChunk();
					if ( $nextChunk == '<q>' ) {
						$this->pushDelimiter( '</q>' );
						$setsubNS = true;
						$innerdesc = $this->addDescription( $innerdesc, $this->getSubqueryDescription( $setsubNS ), SMW_NEGATION_QUERY );
						$chunk = $this->readChunk();
						break;
					} else {
						$chunk .= $nextChunk;
					}
				default: // normal object value
					// read value(s), possibly with inner [[...]]
					$open = 1;
					$value = $chunk;
					$continue2 = true;
					// read value with inner [[, ]], ||
					while ( ( $open > 0 ) && ( $continue2 ) ) {
						$chunk = $this->readChunk( '\[\[|\]\]|\|\||\|' );
						switch ( $chunk ) {
							case '[[': // open new [[ ]]
								$open++;
							break;
							case ']]': // close [[ ]]
								$open--;
							break;
							case '|':
							case '||': // terminates only outermost [[ ]]
								if ( $open == 1 ) {
									$open = 0;
								}
							break;
							case '': ///TODO: report error; this is not good right now
								$continue2 = false;
							break;
						}
						if ( $open != 0 ) {
							$value .= $chunk;
						}
					} ///NOTE: at this point, we normally already read one more chunk behind the value

					$dv = \SMW\DataValueFactory::getInstance()->newPropertyObjectValue( $property->getDataItem() );
					$dv->setQueryConditionUsage( true );
					$vd = $dv->getQueryDescription( $value );
					$innerdesc = $this->addDescription( $innerdesc, $vd, SMW_DISJUNCTION_QUERY );
					$this->errorMessages = $this->errorMessages + $dv->getErrors();
			}
			$continue = ( $chunk == '||' );
		}

		if ( is_null( $innerdesc ) ) { // make a wildcard search
			$innerdesc = ( !is_null( $this->defaultNamespace ) && $this->isPagePropertyType( $typeid ) ) ?
							$this->addDescription( $innerdesc, $this->defaultNamespace, SMW_DISJUNCTION_QUERY ) :
							$this->addDescription( $innerdesc, new ThingDescription(), SMW_DISJUNCTION_QUERY );
			$this->errorMessages[] = wfMessage(
				'smw_propvalueproblem',
				$property->getWikiValue()
			)->inContentLanguage()->text();
		}

		$properties = array_reverse( $properties );

		foreach ( $properties as $property ) {
			$innerdesc = new SomeProperty( $property->getDataItem(), $innerdesc );
		}

		$result = $innerdesc;

		return $this->finishLinkDescription( $chunk, false, $result, $setNS );
	}

	/**
	 * Parse an article description (the part of an inline query that
	 * is in between "[[" and the closing "]]" assuming it is not specifying
	 * a category or property) and create a suitable description.
	 * The first chunk behind the "[[" has already been read and is
	 * passed as a parameter.
	 */
	private function getArticleDescription( $firstChunk, &$setNS ) {
		$chunk = $firstChunk;
		$result = null;
		$continue = true;

		while ( $continue ) {
			if ( $chunk == '<q>' ) { // no subqueries of the form [[<q>...</q>]] (not needed)
				$this->errorMessages[] = wfMessage( 'smw_misplacedsubquery' )->inContentLanguage()->text();
				return null;
			}

			$list = preg_split( '/:/', $chunk, 3 ); // ":Category:Foo" "User:bar"  ":baz" ":+"

			if ( ( $list[0] === '' ) && ( count( $list ) == 3 ) ) {
				$list = array_slice( $list, 1 );
			}
			if ( ( count( $list ) == 2 ) && ( $list[1] == '+' ) ) { // try namespace restriction

				$idx = \SMW\Localizer::getInstance()->getNamespaceIndexByName( $list[0] );

				if ( $idx !== false ) {
					$result = $this->addDescription( $result, new NamespaceDescription( $idx ), SMW_DISJUNCTION_QUERY );
				}
			} else {
				$value = \SMW\DataValueFactory::getInstance()->newTypeIDValue( '_wpg', $chunk );

				if ( $value->isValid() ) {
					$result = $this->addDescription( $result, $value->getQueryDescription( $chunk ), SMW_DISJUNCTION_QUERY );
				}
			}

			$chunk = $this->readChunk( '\[\[|\]\]|\|\||\|' );

			if ( $chunk == '||' ) {
				$chunk = $this->readChunk( '\[\[|\]\]|\|\||\|' );
				$continue = true;
			} else {
				$continue = false;
			}
		}

		return $this->finishLinkDescription( $chunk, true, $result, $setNS );
	}

	private function finishLinkDescription( $chunk, $hasNamespaces, $result, &$setNS ) {
		if ( is_null( $result ) ) { // no useful information or concrete error found
			$this->errorMessages[] = wfMessage( 'smw_badqueryatom' )->inContentLanguage()->text();
		} elseif ( !$hasNamespaces && $setNS && !is_null( $this->defaultNamespace  ) ) {
			$result = $this->addDescription( $result, $this->defaultNamespace );
			$hasNamespaces = true;
		}

		$setNS = $hasNamespaces;

		if ( $chunk == '|' ) { // skip content after single |, but report a warning
			// Note: Using "|label" in query atoms used to be a way to set the mainlabel in SMW <1.0; no longer supported now
			$chunk = $this->readChunk( '\]\]' );
			$labelpart = '|';
			if ( $chunk != ']]' ) {
				$labelpart .= $chunk;
				$chunk = $this->readChunk( '\]\]' );
			}
			$this->errorMessages[] = wfMessage(
				'smw_unexpectedpart',
				$labelpart
			)->inContentLanguage()->escaped();
		}

		if ( $chunk != ']]' ) {
			// What happended? We found some chunk that could not be processed as
			// link content (as in [[Category:Test<q>]]), or the closing ]] are
			// just missing entirely.
			if ( $chunk !== '' ) {
				$this->errorMessages[] = wfMessage(
					'smw_misplacedsymbol',
					$chunk
				)->inContentLanguage()->escaped();

				// try to find a later closing ]] to finish this misshaped subpart
				$chunk = $this->readChunk( '\]\]' );

				if ( $chunk != ']]' ) {
					$chunk = $this->readChunk( '\]\]' );
				}
			}
			if ( $chunk === '' ) {
				$this->errorMessages[] = wfMessage( 'smw_noclosingbrackets' )->inContentLanguage()->text();
			}
		}

		return $result;
	}

	/**
	 * Get the next unstructured string chunk from the query string.
	 * Chunks are delimited by any of the special strings used in inline queries
	 * (such as [[, ]], <q>, ...). If the string starts with such a delimiter,
	 * this delimiter is returned. Otherwise the first string in front of such a
	 * delimiter is returned.
	 * Trailing and initial spaces are ignored if $trim is true, and chunks
	 * consisting only of spaces are not returned.
	 * If there is no more qurey string left to process, the empty string is
	 * returned (and in no other case).
	 *
	 * The stoppattern can be used to customise the matching, especially in order to
	 * overread certain special symbols.
	 *
	 * $consume specifies whether the returned chunk should be removed from the
	 * query string.
	 */
	private function readChunk( $stoppattern = '', $consume = true, $trim = true ) {
		if ( $stoppattern === '' ) {
			$stoppattern = '\!|\[\[|\]\]|::|:=|<q>|<\/q>' .
				'|^' . $this->categoryPrefix . '|^' . $this->categoryPrefixCannonical .
				'|^' . $this->conceptPrefix . '|^' . $this->conceptPrefixCannonical .
				'|\|\||\|';
		}
		$chunks = preg_split( '/[\s]*(' . $stoppattern . ')/iu', $this->currentString, 2, PREG_SPLIT_DELIM_CAPTURE );
		if ( count( $chunks ) == 1 ) { // no matches anymore, strip spaces and finish
			if ( $consume ) {
				$this->currentString = '';
			}

			return $trim ? trim( $chunks[0] ) : $chunks[0];
		} elseif ( count( $chunks ) == 3 ) { // this should generally happen if count is not 1
			if ( $chunks[0] === '' ) { // string started with delimiter
				if ( $consume ) {
					$this->currentString = $chunks[2];
				}

				return $trim ? trim( $chunks[1] ) : $chunks[1];
			} else {
				if ( $consume ) {
					$this->currentString = $chunks[1] . $chunks[2];
				}

				return $trim ? trim( $chunks[0] ) : $chunks[0];
			}
		} else {
			return false;
		} // should never happen
	}

	/**
	 * Enter a new subblock in the query, which must at some time be terminated by the
	 * given $endstring delimiter calling popDelimiter();
	 */
	private function pushDelimiter( $endstring ) {
		array_push( $this->separatorStack, $endstring );
	}

	/**
	 * Exit a subblock in the query ending with the given delimiter.
	 * If the delimiter does not match the top-most open block, false
	 * will be returned. Otherwise return true.
	 */
	private function popDelimiter( $endstring ) {
		$topdelim = array_pop( $this->separatorStack );
		return ( $topdelim == $endstring );
	}

	/**
	 * Extend a given description by a new one, either by adding the new description
	 * (if the old one is a container description) or by creating a new container.
	 * The parameter $conjunction determines whether the combination of both descriptions
	 * should be a disjunction or conjunction.
	 *
	 * In the special case that the current description is NULL, the new one will just
	 * replace the current one.
	 *
	 * The return value is the expected combined description. The object $curdesc will
	 * also be changed (if it was non-NULL).
	 */
	private function addDescription( $curdesc, $newdesc, $queryType = SMW_CONJUNCTION_QUERY ) {
		$notallowedmessage = 'smw_noqueryfeature';
		if ( $newdesc instanceof SomeProperty ) {
			$allowed = $this->queryFeatures & SMW_PROPERTY_QUERY;
		} elseif ( $newdesc instanceof ClassDescription ) {
			$allowed = $this->queryFeatures & SMW_CATEGORY_QUERY;
		} elseif ( $newdesc instanceof ConceptDescription ) {
			$allowed = $this->queryFeatures & SMW_CONCEPT_QUERY;
		} elseif ( $newdesc instanceof Conjunction ) {
			$allowed = $this->queryFeatures & SMW_CONJUNCTION_QUERY;
			$notallowedmessage = 'smw_noconjunctions';
		} elseif ( $newdesc instanceof Disjunction ) {
			$allowed = $this->queryFeatures & SMW_DISJUNCTION_QUERY;
			$notallowedmessage = 'smw_nodisjunctions';
		} else {
			$allowed = true;
		}

		if ( !$allowed ) {
			$this->errorMessages[] = wfMessage(
				$notallowedmessage,
				str_replace( '[', '&#x005B;', $newdesc->getQueryString() )
			)->inContentLanguage()->text();
			return $curdesc;
		}

		if ( is_null( $newdesc ) ) {
			return $curdesc;
		} elseif ( is_null( $curdesc ) ) {
			if ( ( $queryType & SMW_NEGATION_QUERY ) != 0 ) {
				return $newdesc instanceof Negation ? $newdesc->getDescription() : new Negation( $newdesc );
			} else {
				return $newdesc;
			}
		} else { // we already found descriptions
			if ( ( ( $queryType & SMW_CONJUNCTION_QUERY ) != 0 && ( $curdesc instanceof Conjunction ) ) ||
			     ( ( $queryType & SMW_DISJUNCTION_QUERY ) != 0 && ( $curdesc instanceof Disjunction ) ) ) { // use existing container
				$curdesc->addDescription( $newdesc );
				return $curdesc;
			} elseif ( ( $queryType & SMW_CONJUNCTION_QUERY ) != 0 ) { // make new conjunction
				if ( $this->queryFeatures & SMW_CONJUNCTION_QUERY ) {
					return new Conjunction( array( $curdesc, $newdesc ) );
				} else {
					$this->errorMessages[] = wfMessage(
						'smw_noconjunctions',
						str_replace( '[', '&#x005B;', $newdesc->getQueryString() )
					)->inContentLanguage()->text();
					return $curdesc;
				}
			} elseif ( ( $queryType & SMW_DISJUNCTION_QUERY ) != 0 ) { // make new disjunction
				if ( $this->queryFeatures & SMW_DISJUNCTION_QUERY ) {
					return new Disjunction( array( $curdesc, $newdesc ) );
				} else {
					$this->errorMessages[] = wfMessage(
						'smw_nodisjunctions',
						str_replace( '[', '&#x005B;', $newdesc->getQueryString() )
					)->inContentLanguage()->text();
					return $curdesc;
				}
			} elseif ( ( $queryType & SMW_NEGATION_QUERY ) != 0 ) {
				if ( $this->queryFeatures & SMW_NEGATION_QUERY ) {
					return new Conjunction( array( $curdesc, $newdesc instanceof Negation ? $newdesc->getDescription() : new Negation( $newdesc ) ) );
				} else {
					$this->errorMessages[] = wfMessage(
						'smw_nonegations',
						str_replace( '[', '&#x005B;', $newdesc->getQueryString() )
					)->inContentLanguage()->text();
					return $curdesc;
				}
			}
		}
	}

	private function isPagePropertyType( $typeid ) {
		return $typeid == '_wpg' || $typeid == '__sob';
	}

}
