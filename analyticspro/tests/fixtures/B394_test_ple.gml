<?xml version="1.0" encoding="UTF-8"?>
<wfs:FeatureCollection
    xmlns:wfs="http://www.opengis.net/wfs/2.0"
    xmlns:gml="http://www.opengis.net/gml/3.2"
    xmlns:CP="http://mapserver.gis.umn.edu/mapserver"
    numberMatched="3" numberReturned="3">

  <!-- Particella 1: quadrato semplice — centroide atteso: 45.5, 10.5 -->
  <wfs:member>
    <CP:CadastralParcel gml:id="CadastralParcel.IT.AGE.PLA.B394_003300.147">
      <gml:boundedBy>
        <gml:Envelope srsName="urn:ogc:def:crs:EPSG::6706">
          <gml:lowerCorner>45.49 10.49</gml:lowerCorner>
          <gml:upperCorner>45.51 10.51</gml:upperCorner>
        </gml:Envelope>
      </gml:boundedBy>
      <CP:msGeometry>
        <gml:Polygon gml:id="poly1" srsName="urn:ogc:def:crs:EPSG::6706">
          <gml:exterior>
            <gml:LinearRing>
              <gml:posList srsDimension="2">45.49 10.49 45.51 10.49 45.51 10.51 45.49 10.51 45.49 10.49</gml:posList>
            </gml:LinearRing>
          </gml:exterior>
        </gml:Polygon>
      </CP:msGeometry>
      <CP:INSPIREID_LOCALID>IT.AGE.PLA.B394_003300.147</CP:INSPIREID_LOCALID>
      <CP:INSPIREID_NAMESPACE>IT.AGE.PLA.</CP:INSPIREID_NAMESPACE>
      <CP:LABEL>147</CP:LABEL>
      <CP:NATIONALCADASTRALREFERENCE>B394_003300.147</CP:NATIONALCADASTRALREFERENCE>
      <CP:ADMINISTRATIVEUNIT>B394</CP:ADMINISTRATIVEUNIT>
    </CP:CadastralParcel>
  </wfs:member>

  <!-- Particella 2: poligono a L (concavo) -->
  <wfs:member>
    <CP:CadastralParcel gml:id="CadastralParcel.IT.AGE.PLA.B394_003300.148">
      <gml:boundedBy>
        <gml:Envelope srsName="urn:ogc:def:crs:EPSG::6706">
          <gml:lowerCorner>45.52 10.52</gml:lowerCorner>
          <gml:upperCorner>45.56 10.56</gml:upperCorner>
        </gml:Envelope>
      </gml:boundedBy>
      <CP:msGeometry>
        <gml:Polygon gml:id="poly2" srsName="urn:ogc:def:crs:EPSG::6706">
          <gml:exterior>
            <gml:LinearRing>
              <gml:posList srsDimension="2">45.52 10.52 45.56 10.52 45.56 10.56 45.54 10.56 45.54 10.54 45.52 10.54 45.52 10.52</gml:posList>
            </gml:LinearRing>
          </gml:exterior>
        </gml:Polygon>
      </CP:msGeometry>
      <CP:INSPIREID_LOCALID>IT.AGE.PLA.B394_003300.148</CP:INSPIREID_LOCALID>
      <CP:INSPIREID_NAMESPACE>IT.AGE.PLA.</CP:INSPIREID_NAMESPACE>
      <CP:LABEL>148</CP:LABEL>
      <CP:NATIONALCADASTRALREFERENCE>B394_003300.148</CP:NATIONALCADASTRALREFERENCE>
      <CP:ADMINISTRATIVEUNIT>B394</CP:ADMINISTRATIVEUNIT>
    </CP:CadastralParcel>
  </wfs:member>

  <!-- Particella 3: quadrato con buco interno -->
  <wfs:member>
    <CP:CadastralParcel gml:id="CadastralParcel.IT.AGE.PLA.B394_003300.149">
      <gml:boundedBy>
        <gml:Envelope srsName="urn:ogc:def:crs:EPSG::6706">
          <gml:lowerCorner>45.60 10.60</gml:lowerCorner>
          <gml:upperCorner>45.64 10.64</gml:upperCorner>
        </gml:Envelope>
      </gml:boundedBy>
      <CP:msGeometry>
        <gml:Polygon gml:id="poly3" srsName="urn:ogc:def:crs:EPSG::6706">
          <gml:exterior>
            <gml:LinearRing>
              <gml:posList srsDimension="2">45.60 10.60 45.64 10.60 45.64 10.64 45.60 10.64 45.60 10.60</gml:posList>
            </gml:LinearRing>
          </gml:exterior>
          <gml:interior>
            <gml:LinearRing>
              <gml:posList srsDimension="2">45.61 10.61 45.63 10.61 45.63 10.63 45.61 10.63 45.61 10.61</gml:posList>
            </gml:LinearRing>
          </gml:interior>
        </gml:Polygon>
      </CP:msGeometry>
      <CP:INSPIREID_LOCALID>IT.AGE.PLA.B394_003300.149</CP:INSPIREID_LOCALID>
      <CP:INSPIREID_NAMESPACE>IT.AGE.PLA.</CP:INSPIREID_NAMESPACE>
      <CP:LABEL>149</CP:LABEL>
      <CP:NATIONALCADASTRALREFERENCE>B394_003300.149</CP:NATIONALCADASTRALREFERENCE>
      <CP:ADMINISTRATIVEUNIT>B394</CP:ADMINISTRATIVEUNIT>
    </CP:CadastralParcel>
  </wfs:member>

</wfs:FeatureCollection>
