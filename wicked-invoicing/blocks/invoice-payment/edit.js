import { __ } from '@wordpress/i18n';
import {
  useBlockProps,
  InspectorControls,
  MediaUpload,
  MediaUploadCheck,
} from '@wordpress/block-editor';
import {
  PanelBody,
  CheckboxControl,
  SelectControl,
  ColorPalette,
  Button,
  __experimentalGradientPicker,
} from '@wordpress/components';

// Make it optional – will be undefined on some WP versions.
const GradientPicker = __experimentalGradientPicker;

const ALL_PROCESSORS = [
  { value: 'stripe', label: 'Stripe' },
  { value: 'paypal', label: 'PayPal' },
];

const THEME_COLORS = [
  { name: 'Primary', color: '#111827' },
  { name: 'Accent', color: '#2563eb' },
  { name: 'Success', color: '#16a34a' },
  { name: 'Danger', color: '#dc2626' },
];

export default function Edit( { attributes, setAttributes } ) {
  const {
    enabledProcessors = [],
    buttonVariant = 'solid',
    buttonShape = 'rounded',
    buttonSize = 'md',
    buttonAlign = 'right',
    buttonBgColor = '',
    buttonTextColor = '',
    buttonGradient = '',
    backgroundImageId = 0,
    backgroundImageUrl = '',
    backgroundImageWidth = 0,
    backgroundImageHeight = 0,
  } = attributes;

  const classes = [
    'wi-payment-buttons',
    `wi-button-variant-${ buttonVariant }`,
    `wi-button-shape-${ buttonShape }`,
    `wi-button-size-${ buttonSize }`,
    `wi-button-align-${ buttonAlign }`,
  ].join( ' ' );

  // CSS vars for button colors/gradient + optional background image
  const styleVars = {
    ...( buttonBgColor ? { '--wi-btn-bg': buttonBgColor } : {} ),
    ...( buttonTextColor ? { '--wi-btn-text': buttonTextColor } : {} ),
    ...( buttonGradient ? { '--wi-btn-gradient': buttonGradient } : {} ),
    ...( backgroundImageUrl
      ? {
          backgroundImage: `url(${ backgroundImageUrl })`,
          backgroundSize: 'cover',
          backgroundPosition: 'center',
        }
      : {} ),
  };

  const blockProps = useBlockProps( {
    className: classes,
    style: styleVars,
  } );

  const toggleProcessor = ( value ) => {
    const exists = enabledProcessors.includes( value );
    setAttributes( {
      enabledProcessors: exists
        ? enabledProcessors.filter( ( v ) => v !== value )
        : [ ...enabledProcessors, value ],
    } );
  };

  const processorsToShow =
    enabledProcessors.length > 0
      ? ALL_PROCESSORS.filter( ( p ) => enabledProcessors.includes( p.value ) )
      : ALL_PROCESSORS;

  return (
    <>
      <InspectorControls>
        {/* Payment methods */}
        <PanelBody
          title={ __( 'Payment Methods', 'wicked-invoicing' ) }
          initialOpen={ true }
        >
          { ALL_PROCESSORS.map( ( p ) => (
            <CheckboxControl
              key={ p.value }
              label={ p.label }
              checked={ enabledProcessors.includes( p.value ) }
              onChange={ () => toggleProcessor( p.value ) }
              __nextHasNoMarginBottom
            />
          ) ) }
        </PanelBody>

        {/* Button style (variant/size/shape/alignment) */}
        <PanelBody
          title={ __( 'Button Style', 'wicked-invoicing' ) }
          initialOpen={ false }
        >
          <SelectControl
            label={ __( 'Variant', 'wicked-invoicing' ) }
            value={ buttonVariant }
            options={ [
              { label: __( 'Solid', 'wicked-invoicing' ), value: 'solid' },
              { label: __( 'Outline', 'wicked-invoicing' ), value: 'outline' },
              { label: __( 'Ghost', 'wicked-invoicing' ), value: 'ghost' },
            ] }
            onChange={ ( value ) => setAttributes( { buttonVariant: value } ) }
            __nextHasNoMarginBottom
            __next40pxDefaultSize
          />

          <SelectControl
            label={ __( 'Size', 'wicked-invoicing' ) }
            value={ buttonSize }
            options={ [
              { label: __( 'Small', 'wicked-invoicing' ), value: 'sm' },
              { label: __( 'Medium', 'wicked-invoicing' ), value: 'md' },
              { label: __( 'Large', 'wicked-invoicing' ), value: 'lg' },
            ] }
            onChange={ ( value ) => setAttributes( { buttonSize: value } ) }
            __nextHasNoMarginBottom
            __next40pxDefaultSize
          />

          <SelectControl
            label={ __( 'Alignment', 'wicked-invoicing' ) }
            value={ buttonAlign }
            options={ [
              { label: __( 'Left', 'wicked-invoicing' ), value: 'left' },
              { label: __( 'Center', 'wicked-invoicing' ), value: 'center' },
              { label: __( 'Right', 'wicked-invoicing' ), value: 'right' },
            ] }
            onChange={ ( value ) => setAttributes( { buttonAlign: value } ) }
            __nextHasNoMarginBottom
            __next40pxDefaultSize
          />

          <SelectControl
            label={ __( 'Shape', 'wicked-invoicing' ) }
            value={ buttonShape }
            options={ [
              { label: __( 'Rounded', 'wicked-invoicing' ), value: 'rounded' },
              { label: __( 'Pill', 'wicked-invoicing' ), value: 'pill' },
              { label: __( 'Square', 'wicked-invoicing' ), value: 'square' },
            ] }
            onChange={ ( value ) => setAttributes( { buttonShape: value } ) }
            __nextHasNoMarginBottom
            __next40pxDefaultSize
          />
        </PanelBody>

        {/* Button colors + gradient */}
        <PanelBody
          title={ __( 'Button Colors', 'wicked-invoicing' ) }
          initialOpen={ false }
        >
          <p>{ __( 'Background', 'wicked-invoicing' ) }</p>
          <ColorPalette
            colors={ THEME_COLORS }
            value={ buttonBgColor }
            onChange={ ( value ) =>
              setAttributes( { buttonBgColor: value || '' } )
            }
          />

          <p>{ __( 'Text', 'wicked-invoicing' ) }</p>
          <ColorPalette
            colors={ THEME_COLORS }
            value={ buttonTextColor }
            onChange={ ( value ) =>
              setAttributes( { buttonTextColor: value || '' } )
            }
          />

          { GradientPicker && (
            <>
              <p>{ __( 'Gradient (optional)', 'wicked-invoicing' ) }</p>
              <GradientPicker
                value={ buttonGradient }
                onChange={ ( value ) =>
                  setAttributes( { buttonGradient: value || '' } )
                }
              />
            </>
          ) }
        </PanelBody>

        {/* Background image for strip */}
        <PanelBody
          title={ __( 'Background Image', 'wicked-invoicing' ) }
          initialOpen={ false }
        >
          <MediaUploadCheck>
            <MediaUpload
              onSelect={ ( media ) => {
                setAttributes( {
                  backgroundImageId: media.id,
                  backgroundImageUrl: media.url,
                  backgroundImageWidth: media.width || 0,
                  backgroundImageHeight: media.height || 0,
                } );
              } }
              allowedTypes={ [ 'image' ] }
              value={ backgroundImageId || undefined }
              render={ ( { open } ) => (
                <div>
                  { backgroundImageUrl ? (
                    <>
                      <div className="wi-payment-bg-preview">
                        <img
                          src={ backgroundImageUrl }
                          alt=""
                          style={ {
                            maxWidth: '100%',
                            height: 'auto',
                            display: 'block',
                            borderRadius: '4px',
                          } }
                        />
                        { backgroundImageWidth && backgroundImageHeight && (
                          <p>
                            { __( 'Original size:', 'wicked-invoicing' ) }{' ' }
                            { backgroundImageWidth }×{ backgroundImageHeight } px
                          </p>
                        ) }
                      </div>
                      <Button
                        variant="primary"
                        onClick={ open }
                        style={ { marginTop: '8px', marginRight: '8px' } }
                      >
                        { __( 'Change image', 'wicked-invoicing' ) }
                      </Button>
                      <Button
                        variant="secondary"
                        onClick={ () =>
                          setAttributes( {
                            backgroundImageId: 0,
                            backgroundImageUrl: '',
                            backgroundImageWidth: 0,
                            backgroundImageHeight: 0,
                          } )
                        }
                      >
                        { __( 'Remove image', 'wicked-invoicing' ) }
                      </Button>
                    </>
                  ) : (
                    <Button variant="primary" onClick={ open }>
                      { __( 'Select background image', 'wicked-invoicing' ) }
                    </Button>
                  ) }
                </div>
              ) }
            />
          </MediaUploadCheck>
        </PanelBody>
      </InspectorControls>

      <div { ...blockProps }>
        { processorsToShow.map( ( p ) => (
          <button
            key={ p.value }
            type="button"
            className="wi-payment-button"
          >
            { __( 'Pay with ', 'wicked-invoicing' ) }
            { p.label }
          </button>
        ) ) }
      </div>
    </>
  );
}
