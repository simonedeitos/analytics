using System.Globalization;

namespace ADEtoDB.Services;

public sealed class GeometryService
{
    public string? PolygonToWkt(IReadOnlyList<(double Lat, double Lng)> polygonPoints)
    {
        var points = NormalizePolygonPoints(polygonPoints);
        if (points.Count < 4) {
            return null;
        }

        return "POLYGON((" + string.Join(", ", points.Select(point => $"{FormatCoord(point.Lat)} {FormatCoord(point.Lng)}")) + "))";
    }

    public string? PointToWkt((double Lat, double Lng) point)
    {
        if (!double.IsFinite(point.Lat) || !double.IsFinite(point.Lng)) {
            return null;
        }

        return $"POINT({FormatCoord(point.Lat)} {FormatCoord(point.Lng)})";
    }

    public InteriorPointResult ComputePolygonInteriorPoint(IReadOnlyList<(double Lat, double Lng)> polygonPoints)
    {
        var points = PolygonWithoutClosingVertex(polygonPoints);
        if (points.Count < 3) {
            return new InteriorPointResult(null, false, "invalid_polygon");
        }

        var centroid = PolygonSimpleCentroid(points);
        if (centroid is { } centerPoint && PointInPolygon(centerPoint, polygonPoints)) {
            return new InteriorPointResult(centerPoint, true, "centroid");
        }

        var maxVertices = Math.Min(5, points.Count);
        for (var index = 0; index < maxVertices; index++) {
            if (PointInPolygon(points[index], polygonPoints)) {
                return new InteriorPointResult(points[index], true, "vertex_fallback");
            }
        }

        if (centroid is { } fallbackCenter) {
            foreach (var index in NearestVertexIndexes(fallbackCenter, points, 5)) {
                var nextIndex = (index + 1) % points.Count;
                var midpoint = (
                    Lat: (points[index].Lat + points[nextIndex].Lat) / 2d,
                    Lng: (points[index].Lng + points[nextIndex].Lng) / 2d
                );

                if (PointInPolygon(midpoint, polygonPoints)) {
                    return new InteriorPointResult(midpoint, true, "edge_midpoint_fallback");
                }
            }

            return new InteriorPointResult(fallbackCenter, false, "centroid_outside_fallback");
        }

        return new InteriorPointResult(points[0], false, "vertex_outside_fallback");
    }

    private static string FormatCoord(double value)
    {
        var formatted = value.ToString("0.##########", CultureInfo.InvariantCulture);
        return string.IsNullOrWhiteSpace(formatted) ? "0" : formatted;
    }

    private static List<(double Lat, double Lng)> NormalizePolygonPoints(IEnumerable<(double Lat, double Lng)> points)
    {
        var normalized = new List<(double Lat, double Lng)>();

        foreach (var point in points) {
            if (!double.IsFinite(point.Lat) || !double.IsFinite(point.Lng)) {
                continue;
            }

            if (normalized.Count > 0 && Math.Abs(normalized[^1].Lat - point.Lat) < 1e-12 && Math.Abs(normalized[^1].Lng - point.Lng) < 1e-12) {
                continue;
            }

            normalized.Add(point);
        }

        if (normalized.Count >= 2) {
            var first = normalized[0];
            var last = normalized[^1];
            if (Math.Abs(first.Lat - last.Lat) > 1e-12 || Math.Abs(first.Lng - last.Lng) > 1e-12) {
                normalized.Add(first);
            }
        }

        return normalized;
    }

    private static List<(double Lat, double Lng)> PolygonWithoutClosingVertex(IReadOnlyList<(double Lat, double Lng)> polygonPoints)
    {
        var points = NormalizePolygonPoints(polygonPoints);
        if (points.Count >= 2) {
            var first = points[0];
            var last = points[^1];
            if (Math.Abs(first.Lat - last.Lat) < 1e-12 && Math.Abs(first.Lng - last.Lng) < 1e-12) {
                points.RemoveAt(points.Count - 1);
            }
        }

        return points;
    }

    private static (double Lat, double Lng)? PolygonSimpleCentroid(IReadOnlyList<(double Lat, double Lng)> points)
    {
        if (points.Count == 0) {
            return null;
        }

        return (
            Lat: points.Average(point => point.Lat),
            Lng: points.Average(point => point.Lng)
        );
    }

    private static IReadOnlyList<int> NearestVertexIndexes((double Lat, double Lng) center, IReadOnlyList<(double Lat, double Lng)> points, int limit)
    {
        return points
            .Select((point, index) => new
            {
                Index = index,
                Distance = Math.Pow(point.Lng - center.Lng, 2d) + Math.Pow(point.Lat - center.Lat, 2d),
            })
            .OrderBy(entry => entry.Distance)
            .Take(limit)
            .Select(entry => entry.Index)
            .ToList();
    }

    private static bool PointInPolygon((double Lat, double Lng) point, IReadOnlyList<(double Lat, double Lng)> polygonPoints)
    {
        var polygon = PolygonWithoutClosingVertex(polygonPoints);
        if (polygon.Count < 3) {
            return false;
        }

        var inside = false;
        for (int i = 0, j = polygon.Count - 1; i < polygon.Count; j = i++) {
            var xi = polygon[i].Lng;
            var yi = polygon[i].Lat;
            var xj = polygon[j].Lng;
            var yj = polygon[j].Lat;

            var pointOnHorizontalEdge = Math.Abs(yi - point.Lat) < 1e-10
                && Math.Abs(yj - point.Lat) < 1e-10
                && point.Lng >= Math.Min(xi, xj)
                && point.Lng <= Math.Max(xi, xj);

            if (pointOnHorizontalEdge) {
                return true;
            }

            var intersects = ((yi > point.Lat) != (yj > point.Lat))
                && point.Lng <= ((xj - xi) * (point.Lat - yi) / ((yj - yi) == 0d ? 1e-10 : (yj - yi)) + xi);

            if (intersects) {
                inside = !inside;
            }
        }

        return inside;
    }
}

public sealed record InteriorPointResult((double Lat, double Lng)? Point, bool Inside, string Strategy);
